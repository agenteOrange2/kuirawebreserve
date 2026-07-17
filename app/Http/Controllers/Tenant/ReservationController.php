<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Actions\Reservations\UpdateReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateReservationRequest;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reservations = Reservation::query()
            ->with(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->date('from'), fn ($q, $from) => $q->where('ends_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->where('starts_at', '<=', $to))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $r) => $this->serialize($r));

        return response()->json($reservations);
    }

    public function store(Request $request, CreateReservation $action): JsonResponse
    {
        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'room_id' => ['nullable', 'exists:rooms,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_id' => ['nullable', 'exists:guests,id'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'num_people' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:100'],
            'eta' => ['nullable', 'date_format:H:i'],
            'confirmed' => ['sometimes', 'boolean'],
            'source_channel' => ['sometimes', Rule::in(['front_desk', 'phone', 'web', 'whatsapp', 'walk_in'])],
            'deposit_amount' => ['sometimes', 'numeric', 'min:0'],
            // Conceptos de cargos opcionales de la habitación; el monto
            // SIEMPRE se resuelve del catálogo del cuarto, nunca del cliente.
            'extra_charges' => ['sometimes', 'array', 'max:20'],
            'extra_charges.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'guest_notes' => ['nullable', 'string'],
        ]);

        try {
            $reservation = $action->handle($data, $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($reservation->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])), 201);
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation, UpdateReservation $action): JsonResponse
    {
        try {
            $reservation = $action->handle($reservation, $request->validated(), $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($reservation->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])));
    }

    public function confirm(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        return $this->transition(fn () => $action->confirm($reservation, $request->user()), $reservation);
    }

    public function cancel(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        $data = $request->validate([
            'no_show' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $to = $request->boolean('no_show') ? ReservationStatus::NoShow : ReservationStatus::Cancelled;

        return $this->transition(
            fn () => $action->cancel($reservation, $request->user(), $to, $data['reason'] ?? null),
            $reservation,
        );
    }

    public function checkIn(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        return $this->transition(fn () => $action->checkIn($reservation, $request->user()), $reservation);
    }

    /**
     * Borrado en masa desde el Historial. Solo acepta estados terminales
     * (completada, cancelada, no-show): una reserva viva no se elimina,
     * primero se cancela. Pagos y solicitudes de cobro caen en cascada;
     * estancias y conversaciones solo pierden la referencia.
     */
    public function destroyBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $reservations = Reservation::query()
            ->whereIn('id', $data['ids'])
            ->whereIn('status', [
                ReservationStatus::Completed,
                ReservationStatus::Cancelled,
                ReservationStatus::NoShow,
            ])
            ->get();

        if ($reservations->count() !== count($data['ids'])) {
            return response()->json([
                'message' => 'Solo se pueden eliminar reservas del historial (completadas, canceladas o no-show).',
            ], 422);
        }

        DB::transaction(fn () => $reservations->each->delete());

        return response()->json(['deleted' => $reservations->count()]);
    }

    /**
     * Registra un abono (anticipo o liquidación) — spec §7.5.
     */
    public function registerPayment(Request $request, Reservation $reservation, RegisterReservationPayment $action): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $action->handle($reservation, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    protected function transition(callable $fn, Reservation $reservation): JsonResponse
    {
        try {
            $fn();
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'code' => $r->displayCode(),
            'guest_id' => $r->guest_id,
            'guest_name' => $r->guest_name,
            'num_people' => $r->num_people,
            'adults' => $r->adults,
            'children' => $r->children,
            'vehicle_plate' => $r->vehicle_plate,
            'vehicle_desc' => $r->vehicle_desc,
            'eta' => $r->eta ? substr($r->eta, 0, 5) : null,
            'guest_notes' => $r->guest_notes,
            'cancellation_reason' => $r->cancellation_reason,
            'room' => $r->room?->number,
            'room_id' => $r->room_id,
            'room_type' => $r->roomType?->name,
            'rate_plan' => $r->ratePlan?->name,
            'starts_at' => $r->starts_at->format('d/m/Y H:i'),
            'ends_at' => $r->ends_at->format('d/m/Y H:i'),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'hold_expires_at' => $r->hold_expires_at?->format('d/m/Y H:i'),
            'source_channel' => $r->source_channel,
            'total_amount' => $r->total_amount,
            'extra_charges' => $r->extra_charges ?? [],
            'deposit_amount' => $r->deposit_amount,
            'payment_status' => $r->payment_status->value,
            'payment_status_label' => $r->payment_status->label(),
            'payment_due_at' => $r->payment_due_at?->format('d/m/Y H:i'),
            'payment_overdue' => $r->isPaymentOverdue(),
            'paid_total' => $r->paidTotal(),
            'pending_balance' => $r->pendingBalance(),
            'payments' => $r->payments()->latest('paid_at')->get()->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'method' => Payment::methodLabel($p->method),
                'reference' => $p->reference,
                'paid_at' => $p->paid_at->format('d/m/Y H:i'),
                'received_by' => $p->receivedBy?->name,
                // F4: cuánto puede devolverse de este pago todavía.
                'refunded' => $p->refundedTotal(),
                'refundable' => $p->refundableAmount(),
                'via_gateway' => $p->gateway !== null,
            ]),
            'refunded_total' => $r->refundedTotal(),
            // Sugerencia por política de cancelación (si la tarifa la define):
            // "si se cancela ahora / ya cancelada, correspondería devolver X".
            'refund_suggestion' => ($suggestion = $r->suggestedRefund()) !== null ? [
                'amount' => $suggestion,
                'amount_label' => '$'.number_format($suggestion, 2),
                'policy_label' => $r->ratePlan?->cancellationPolicyLabel(),
            ] : null,
            'stay_id' => $r->stay?->id,
            // Cobro en curso (spec-pagos §7.5): link vivo para copiar/enviar.
            'payment_request' => ($pr = $r->paymentRequests()->active()->latest('id')->first()) ? [
                'id' => $pr->id,
                'concept' => $pr->conceptLabel(),
                'amount_label' => $pr->amountLabel(),
                'method' => $pr->method,
                'provider_label' => $pr->provider ? (\App\Models\Central\PaymentGatewayLink::PROVIDERS[$pr->provider] ?? $pr->provider) : null,
                'checkout_url' => $pr->checkout_url,
                'public_url' => route('tenant.payment.return', $pr->uuid),
                'status_label' => $pr->statusLabel(),
                'expires_label' => $pr->expires_at?->diffForHumans(),
            ] : null,
        ];
    }

    /**
     * Genera un cobro para la reserva desde el panel (spec-pagos §7.5):
     * link de pasarela si hay una activa, o transferencia con las cuentas
     * del hotel. Reutiliza el mismo IssuePaymentRequest que el bot.
     */
    public function issuePayment(Request $request, Reservation $reservation, \App\Actions\Payments\IssuePaymentRequest $action): JsonResponse
    {
        $link = \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->orderBy('id')
            ->first();

        try {
            $action->handle($reservation, \App\Models\PaymentRequest::METHOD_TRANSFER, $request->user(), $link);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            // La pasarela falló: cae a transferencia (spec-pagos §7.1).
            try {
                $action->handle($reservation, \App\Models\PaymentRequest::METHOD_TRANSFER, $request->user());
            } catch (InvalidArgumentException $inner) {
                return response()->json(['message' => $inner->getMessage()], 422);
            }
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    /**
     * Reembolsa un pago, total o parcial (spec-pagos F4). Pasarela = via API
     * del proveedor; manual = solo registro (efectivo o hecho en el dashboard).
     */
    public function refundPayment(Request $request, Reservation $reservation, Payment $payment, \App\Actions\Payments\RefundPayment $action): JsonResponse
    {
        abort_unless($payment->reservation_id === $reservation->id, 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'manual' => ['sometimes', 'boolean'],
        ]);

        try {
            $refund = $action->handle(
                $payment,
                (float) $data['amount'],
                $data['reason'] ?? null,
                $request->user(),
                (bool) ($data['manual'] ?? false),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(\App\Services\Payments\PaymentGuestNotifier::class)->refundIssued($refund);

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    /** Cancela el cobro pendiente de la reserva (spec-pagos §7.5). */
    public function cancelPayment(Reservation $reservation, \App\Models\PaymentRequest $paymentRequest): JsonResponse
    {
        abort_unless($paymentRequest->reservation_id === $reservation->id, 404);

        if ($paymentRequest->status === \App\Models\PaymentRequest::STATUS_PENDING) {
            $paymentRequest->update(['status' => \App\Models\PaymentRequest::STATUS_CANCELED]);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }
}
