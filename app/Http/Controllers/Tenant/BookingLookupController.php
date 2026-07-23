<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta pública de reserva (/reserva): el huésped busca con su código
 * y su teléfono, y ve el resumen — estado, fechas, cuánto ha pagado y qué
 * falta, con el link de pago vigente si hay uno. El teléfono es la llave
 * anti-curiosos: el código solo no basta (viaja en mensajes y capturas),
 * y la respuesta de "no encontrado" es la misma exista o no el código.
 */
class BookingLookupController extends Controller
{
    public function page(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        // Misma apariencia que el wizard de habitaciones (/reservas/ajustes):
        // una sola configuración para todas las páginas públicas.
        $appearance = $property->wizardAppearance();

        return Inertia::render('tenant/reservar/Lookup', [
            'appearance' => $appearance,
            'property' => [
                'name' => $property->name,
                'logo_url' => $appearance['logo_url'],
                'phone' => $settings['phone'] ?? null,
                'currency' => $settings['currency'] ?? 'MXN',
            ],
        ]);
    }

    public function find(Request $request): JsonResponse
    {
        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        return $this->summary($reservation);
    }

    /**
     * Cancelación autoservicio: solo cuando no hay dinero en riesgo — nada
     * pagado, o dentro de la ventana sin costo de la política de la tarifa.
     * Con dinero de por medio fuera de ventana, la decisión (retenciones,
     * reembolsos) es del hotel, no de un botón público.
     */
    public function cancel(Request $request): JsonResponse
    {
        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        [$allowed, $reason] = $this->selfCancelState($reservation);

        if (! $allowed) {
            return response()->json(['message' => $reason], 422);
        }

        app(\App\Actions\Reservations\TransitionReservation::class)->cancel(
            $reservation,
            null,
            reason: 'Cancelada por el huésped desde la consulta pública.',
        );

        return $this->summary($reservation->refresh()->load(['roomType', 'ratePlan', 'guest']));
    }

    protected function resolve(Request $request): ?Reservation
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        // Se tolera el código sin el prefijo RES- (el huésped a veces solo
        // copia los números).
        $code = strtoupper(trim($data['code']));

        $reservation = Reservation::query()
            ->with(['roomType', 'ratePlan', 'guest'])
            ->whereIn('code', array_unique([$code, 'RES-'.ltrim($code, 'RES-')]))
            ->first();

        if (! $reservation || ! $this->phoneMatches($reservation, $data['phone'])) {
            return null;
        }

        return $reservation;
    }

    protected function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'No encontramos una reserva con ese código y teléfono. Revisa ambos datos e intenta de nuevo.',
        ], 404);
    }

    protected function summary(Reservation $reservation): JsonResponse
    {
        $pending = $reservation->paymentRequests()->active()->latest('id')->first();

        $settings = Property::firstOrFail()->settings ?? [];
        $accounts = $pending?->method === PaymentRequest::METHOD_TRANSFER
            ? collect($settings['bank_accounts'] ?? [])
                ->filter(fn (array $a) => ! empty($a['active']))
                ->map(fn (array $a) => [
                    'banco' => $a['bank'] ?? '',
                    'titular' => $a['holder'] ?? '',
                    'cuenta' => $a['clabe'] ?? '',
                ])
                ->values()
            : collect();

        return response()->json([
            'code' => $reservation->displayCode(),
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'room_type' => $reservation->roomType?->name,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            'total' => (float) $reservation->total_amount,
            'paid' => $reservation->paidTotal(),
            'pending_balance' => $reservation->pendingBalance(),
            'payment_status_label' => $reservation->payment_status->label(),
            'payment_due_at' => $reservation->payment_due_at?->toIso8601String(),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            // Cobro vigente: con pasarela el huésped puede pagar desde aquí
            // mismo; con transferencia se le repiten las cuentas.
            'pending_request' => $pending ? [
                'method' => $pending->method,
                'amount' => (float) $pending->amount,
                'amount_label' => $pending->amountLabel(),
                'checkout_url' => $pending->checkout_url,
                'expires_at' => $pending->expires_at?->toIso8601String(),
                'bank_accounts' => $accounts,
            ] : null,
            'can_cancel' => $this->selfCancelState($reservation)[0],
            'cancellation_policy' => $reservation->ratePlan?->cancellationPolicyLabel(),
        ]);
    }

    /**
     * ¿Puede cancelar el propio huésped? y si no, por qué.
     *
     * @return array{0: bool, 1: string}
     */
    protected function selfCancelState(Reservation $reservation): array
    {
        if (! in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)) {
            return [false, 'Esta reserva ya no se puede cancelar desde aquí; contacta al hotel.'];
        }

        // Sin dinero pagado no hay nada en riesgo: se cancela directo.
        if ($reservation->paidTotal() <= 0) {
            return [true, ''];
        }

        $deadline = $reservation->ratePlan?->cancelFreeDeadlineFor($reservation->starts_at);

        if ($deadline !== null && now()->lte($deadline)) {
            return [true, ''];
        }

        return [false, 'Tu reserva tiene pagos registrados y ya no está en la ventana de cancelación sin costo; contacta al hotel para revisar tu caso.'];
    }

    /**
     * El teléfono capturado debe coincidir con el de la reserva: se
     * comparan los últimos 8 dígitos (tolera lada de país presente o
     * ausente en cualquiera de los dos lados), mínimo 4 capturados.
     */
    protected function phoneMatches(Reservation $reservation, string $input): bool
    {
        // El contacto vive en el Guest ligado, no en la reserva.
        $stored = preg_replace('/\D+/', '', (string) $reservation->guest?->phone);
        $given = preg_replace('/\D+/', '', $input);

        if ($stored === '' || strlen($given) < 4) {
            return false;
        }

        $length = min(8, strlen($stored), strlen($given));

        return substr($stored, -$length) === substr($given, -$length);
    }
}
