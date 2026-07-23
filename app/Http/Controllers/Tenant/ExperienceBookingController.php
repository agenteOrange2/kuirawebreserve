<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Experiences\CreateExperienceBooking;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\ExperienceBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Reservas de experiencias desde el panel: registrar una (huésped que
 * llama o llega a recepción) y mover su estado. El cupo lo hace cumplir
 * CreateExperienceBooking bajo lock, igual que el wizard público.
 */
class ExperienceBookingController extends Controller
{
    public function store(Request $request, CreateExperienceBooking $action): JsonResponse
    {
        $data = $request->validate([
            'experience_session_id' => ['required', 'integer', 'exists:experience_sessions,id'],
            'people' => ['required', 'integer', 'min:1', 'max:500'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['sometimes', 'boolean'],
        ]);

        try {
            $booking = $action->handle($data, $request->user());
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(self::serialize($booking->load(['session.experience', 'guest'])), 201);
    }

    public function updateStatus(Request $request, ExperienceBooking $booking): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                ExperienceBooking::STATUS_CONFIRMED,
                ExperienceBooking::STATUS_CANCELLED,
                ExperienceBooking::STATUS_COMPLETED,
            ])],
        ]);

        if ($booking->status === ExperienceBooking::STATUS_CANCELLED && $data['status'] !== ExperienceBooking::STATUS_CANCELLED) {
            // Revivir una cancelada vuelve a ocupar cupo: se valida duro.
            $session = $booking->session;
            if ($booking->people > $session->remainingSpots()) {
                return response()->json(['message' => 'La sesión ya no tiene cupo para revivir esta reserva.'], 422);
            }
        }

        $booking->update(['status' => $data['status']]);

        return response()->json(self::serialize($booking->fresh()->load(['session.experience', 'guest'])));
    }

    /**
     * Depura el historial: borra en masa reservas de experiencia ya
     * MUERTAS (canceladas o completadas) — mismo patrón que el historial de
     * /reservas. Las vivas (pendientes/confirmadas) no se tocan: para esas
     * está cancelar. Un pago registrado no bloquea aquí porque la
     * experiencia ya terminó su ciclo; el rastro del pago vive en payments.
     */
    public function destroyBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $bookings = ExperienceBooking::query()
            ->whereIn('id', $data['ids'])
            ->whereIn('status', [ExperienceBooking::STATUS_CANCELLED, ExperienceBooking::STATUS_COMPLETED])
            ->get();

        if ($bookings->count() !== count($data['ids'])) {
            return response()->json([
                'message' => 'Solo se pueden eliminar reservas canceladas o completadas; las vivas se cancelan primero.',
            ], 422);
        }

        $deleted = 0;
        foreach ($bookings as $booking) {
            $booking->delete();
            $deleted++;
        }

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Genera el cobro de la reserva (link de pasarela si hay una activa;
     * transferencia si no) — el staff se lo comparte al huésped por el
     * canal que sea. El webhook o la verificación humana lo cierran.
     */
    public function issuePayment(Request $request, ExperienceBooking $booking, \App\Actions\Experiences\IssueExperiencePayment $issuer): JsonResponse
    {
        // Un tour comprado como PLUS de una reserva o grupo ya viaja en el
        // cobro del padre: cobrarlo por separado duplicaría el dinero. Su
        // pago se cierra con el de la reserva/grupo.
        if ($booking->reservation_id !== null || $booking->reservation_group_id !== null) {
            $code = $booking->reservation?->displayCode() ?? $booking->group?->displayCode();

            return response()->json([
                'message' => "Este recorrido es un extra de {$code}; se cobra junto con esa reserva, no por separado.",
            ], 422);
        }

        $gate = app(\App\Services\Payments\PaymentMethodGate::class);
        $enabled = $gate->methodsFor((string) tenant('id'));

        $link = \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', array_keys(array_filter([
                'stripe' => $enabled['stripe'],
                'mercadopago' => $enabled['mercadopago'],
                'paypal' => $enabled['paypal'],
            ])))
            ->orderBy('id')
            ->first();

        try {
            $paymentRequest = $link
                ? $issuer->handle($booking, \App\Models\PaymentRequest::METHOD_GATEWAY, $request->user(), $link)
                : $issuer->handle($booking, \App\Models\PaymentRequest::METHOD_TRANSFER, $request->user());
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            ...self::serialize($booking->fresh()->load(['session.experience', 'guest'])),
            'payment' => [
                'method' => $paymentRequest->method,
                'amount_label' => $paymentRequest->amountLabel(),
                'checkout_url' => $paymentRequest->checkout_url,
                'return_url' => route('tenant.payment.return', $paymentRequest->uuid),
            ],
        ], 201);
    }

    /** @return array<string, mixed> */
    public static function serialize(ExperienceBooking $booking): array
    {
        $pending = $booking->paymentRequests()->active()->latest('id')->first();

        return [
            'id' => $booking->id,
            'code' => $booking->displayCode(),
            'experience' => $booking->session?->experience?->name,
            'session_starts_at' => $booking->session?->starts_at?->toIso8601String(),
            'guest_name' => $booking->guest_name ?? $booking->guest?->full_name,
            'guest_phone' => $booking->guest?->phone,
            'people' => $booking->people,
            'total' => (float) $booking->total,
            'status' => $booking->status,
            'status_label' => $booking->statusLabel(),
            // Si nació como plus de una reserva o grupo, aquí se ve de cuál.
            'linked_to' => $booking->reservation?->displayCode() ?? $booking->group?->displayCode(),
            'notes' => $booking->notes,
            'created_at' => $booking->created_at->toIso8601String(),
            'paid' => $booking->isPaid(),
            'pending_payment' => $pending ? [
                'method' => $pending->method,
                'amount_label' => $pending->amountLabel(),
                'checkout_url' => $pending->checkout_url,
            ] : null,
        ];
    }
}
