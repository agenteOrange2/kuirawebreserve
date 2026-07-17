<?php

namespace App\Actions\Payments;

use App\Actions\Reservations\TransitionReservation;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cierra una solicitud de cobro con dinero confirmado (spec-pagos §4.2):
 * la transferencia que el staff verificó (F0) o el webhook firmado de la
 * pasarela (F1). A diferencia del registro de mostrador, aquí NUNCA se
 * rechaza el dinero — si la reserva se canceló o el monto excede el
 * pendiente, se registra igual y la anomalía queda marcada y alertada
 * (§6.2, §6.3); la verdad contable manda.
 */
class RegisterGatewayPayment
{
    public function __construct(
        protected AvailabilityService $availability,
        protected TransitionReservation $transition,
    ) {}

    /**
     * @param  array{reference?: string|null, notes?: string|null, gateway?: string|null, gateway_ref?: string|null, fee_amount?: float|null}  $data
     */
    public function handle(PaymentRequest $request, array $data = [], ?User $verifier = null): Payment
    {
        return DB::transaction(function () use ($request, $data, $verifier) {
            $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            // Idempotencia: un evento repetido devuelve el pago original.
            if ($request->status === PaymentRequest::STATUS_PAID && $request->payment_id) {
                return Payment::findOrFail($request->payment_id);
            }

            if (! in_array($request->status, [PaymentRequest::STATUS_PENDING, PaymentRequest::STATUS_EXPIRED, PaymentRequest::STATUS_CANCELED], true)) {
                throw new InvalidArgumentException("La solicitud está \"{$request->statusLabel()}\"; no puede cobrarse.");
            }

            // Cobro de experiencia: mismo motor, otro sujeto — el dinero
            // también se registra SIEMPRE y la reserva del tour se confirma.
            if ($request->isForExperience()) {
                return $this->handleExperience($request, $data, $verifier);
            }

            // Cobro consolidado de grupo: un pago que se reparte por
            // habitación según el desglose congelado al emitirse.
            if ($request->isForGroup()) {
                return $this->handleGroup($request, $data, $verifier);
            }

            $reservation = Reservation::whereKey($request->reservation_id)->lockForUpdate()->firstOrFail();

            $anomalies = [];

            if ((float) $request->amount > $reservation->pendingBalance()) {
                $anomalies['overpaid'] = true; // se reembolsa la diferencia, decisión humana
            }

            $payment = $reservation->payments()->create([
                'payment_request_id' => $request->id,
                'amount' => $request->amount,
                'fee_amount' => $data['fee_amount'] ?? null,
                'method' => $request->method === PaymentRequest::METHOD_TRANSFER ? 'transfer' : Payment::METHOD_ONLINE,
                'gateway' => $data['gateway'] ?? $request->provider,
                'gateway_ref' => $data['gateway_ref'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                // Transferencia: quien la verificó. Pasarela: nadie de
                // mostrador (null) — así no contamina el corte de caja (§9.4).
                'received_by' => $verifier?->id,
                'paid_at' => now(),
                'created_at' => now(),
            ]);

            $reservation->syncPaymentStatus();

            // El pago llegó con la reserva ya cancelada (hold vencido, §6.2):
            // se intenta revivir; si la habitación ya se vendió, alerta.
            if ($reservation->status === ReservationStatus::Cancelled) {
                $anomalies = [...$anomalies, ...$this->reviveOrFlag($reservation, $verifier)];
            }

            // Anticipo cubierto + auto-confirmación activa → se confirma sola.
            if ($this->shouldAutoConfirm($reservation->refresh())) {
                try {
                    // notifyGuest false: paymentReceived ya dice "esta confirmada".
                    $this->transition->confirm($reservation, $verifier, notifyGuest: false);
                } catch (NoAvailabilityException) {
                    $anomalies['requires_attention'] = true;
                    $anomalies['attention_reason'] = 'Pago recibido pero la habitación ya no está disponible.';
                }
            }

            $request->update([
                'status' => PaymentRequest::STATUS_PAID,
                'payment_id' => $payment->id,
                'meta' => array_merge($request->meta ?? [], $anomalies),
            ]);

            return $payment;
        });
    }

    /**
     * Reparte el pago del grupo en pagos por reserva (el desglose viene
     * congelado en meta desde IssueGroupPayment) y confirma lo que el
     * dinero cubra — cada reserva con las mismas reglas de siempre.
     *
     * @param  array{reference?: string|null, notes?: string|null, gateway?: string|null, gateway_ref?: string|null, fee_amount?: float|null}  $data
     */
    protected function handleGroup(PaymentRequest $request, array $data, ?User $verifier): Payment
    {
        $breakdown = $request->meta['breakdown'] ?? [];
        $anomalies = [];
        $first = null;

        foreach ($breakdown as $reservationId => $share) {
            $reservation = Reservation::whereKey((int) $reservationId)->lockForUpdate()->first();

            if (! $reservation) {
                $anomalies['requires_attention'] = true;
                $anomalies['attention_reason'] = 'Una reserva del grupo ya no existe; revisar el reparto del pago.';

                continue;
            }

            $payment = $reservation->payments()->create([
                'payment_request_id' => $request->id,
                'amount' => $share,
                // El fee del gateway es del cobro completo: se anota solo en
                // el primer pago para no duplicarlo en la conciliación.
                'fee_amount' => $first === null ? ($data['fee_amount'] ?? null) : null,
                'method' => $request->method === PaymentRequest::METHOD_TRANSFER ? 'transfer' : Payment::METHOD_ONLINE,
                'gateway' => $data['gateway'] ?? $request->provider,
                'gateway_ref' => $data['gateway_ref'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_by' => $verifier?->id,
                'paid_at' => now(),
                'created_at' => now(),
            ]);

            $first ??= $payment;

            $reservation->syncPaymentStatus();

            if ($reservation->status === ReservationStatus::Cancelled) {
                $anomalies = [...$anomalies, ...$this->reviveOrFlag($reservation, $verifier)];
            }

            if ($this->shouldAutoConfirm($reservation->refresh())) {
                try {
                    $this->transition->confirm($reservation, $verifier, notifyGuest: false);
                } catch (NoAvailabilityException) {
                    $anomalies['requires_attention'] = true;
                    $anomalies['attention_reason'] = 'Pago del grupo recibido pero una habitación ya no está disponible.';
                }
            }
        }

        // Experiencias del grupo: cada tour recibe su pago según el reparto
        // congelado y queda firme — mismo criterio que handleExperience.
        foreach ($request->meta['experience_breakdown'] ?? [] as $bookingId => $share) {
            $booking = \App\Models\ExperienceBooking::whereKey((int) $bookingId)->lockForUpdate()->first();

            if (! $booking) {
                $anomalies['requires_attention'] = true;
                $anomalies['attention_reason'] = 'Una experiencia del grupo ya no existe; revisar el reparto del pago.';

                continue;
            }

            $payment = Payment::create([
                'experience_booking_id' => $booking->id,
                'payment_request_id' => $request->id,
                'amount' => $share,
                'fee_amount' => $first === null ? ($data['fee_amount'] ?? null) : null,
                'method' => $request->method === PaymentRequest::METHOD_TRANSFER ? 'transfer' : Payment::METHOD_ONLINE,
                'gateway' => $data['gateway'] ?? $request->provider,
                'gateway_ref' => $data['gateway_ref'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_by' => $verifier?->id,
                'paid_at' => now(),
                'created_at' => now(),
            ]);

            $first ??= $payment;

            if ($booking->status === \App\Models\ExperienceBooking::STATUS_PENDING) {
                $booking->update(['status' => \App\Models\ExperienceBooking::STATUS_CONFIRMED]);
            } elseif ($booking->status === \App\Models\ExperienceBooking::STATUS_CANCELLED) {
                $anomalies['requires_attention'] = true;
                $anomalies['attention_reason'] = 'Pago del grupo recibido con una experiencia cancelada: reubicar o reembolsar.';
            }
        }

        if ($first === null) {
            throw new InvalidArgumentException('El cobro del grupo no tiene desglose por reserva; no se puede repartir.');
        }

        $request->update([
            'status' => PaymentRequest::STATUS_PAID,
            'payment_id' => $first->id,
            'meta' => array_merge($request->meta ?? [], $anomalies),
        ]);

        return $first;
    }

    /**
     * @param  array{reference?: string|null, notes?: string|null, gateway?: string|null, gateway_ref?: string|null, fee_amount?: float|null}  $data
     */
    protected function handleExperience(PaymentRequest $request, array $data, ?User $verifier): Payment
    {
        $booking = \App\Models\ExperienceBooking::whereKey($request->experience_booking_id)
            ->lockForUpdate()
            ->firstOrFail();

        $anomalies = [];

        $payment = Payment::create([
            'experience_booking_id' => $booking->id,
            'payment_request_id' => $request->id,
            'amount' => $request->amount,
            'fee_amount' => $data['fee_amount'] ?? null,
            'method' => $request->method === PaymentRequest::METHOD_TRANSFER ? 'transfer' : Payment::METHOD_ONLINE,
            'gateway' => $data['gateway'] ?? $request->provider,
            'gateway_ref' => $data['gateway_ref'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'received_by' => $verifier?->id,
            'paid_at' => now(),
            'created_at' => now(),
        ]);

        if ($booking->status === \App\Models\ExperienceBooking::STATUS_PENDING) {
            $booking->update(['status' => \App\Models\ExperienceBooking::STATUS_CONFIRMED]);
        } elseif ($booking->status === \App\Models\ExperienceBooking::STATUS_CANCELLED) {
            // El dinero llegó con la reserva del tour ya cancelada: se
            // registra igual y el staff decide (reubicar o reembolsar).
            $anomalies['requires_attention'] = true;
            $anomalies['attention_reason'] = 'Pago recibido con la reserva de experiencia cancelada: reubicar o reembolsar.';
        }

        $request->update([
            'status' => PaymentRequest::STATUS_PAID,
            'payment_id' => $payment->id,
            'meta' => array_merge($request->meta ?? [], $anomalies),
        ]);

        return $payment;
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviveOrFlag(Reservation $reservation, ?User $user): array
    {
        $room = $reservation->room;

        if ($room && $this->availability->isRoomAvailable($room, $reservation->starts_at, $reservation->ends_at, $reservation->id)) {
            $reservation->update([
                'status' => ReservationStatus::Pending, // confirm() la lleva a Confirmed
                'hold_expires_at' => now()->addMinutes(5),
                'cancellation_reason' => null,
            ]);

            return ['revived' => true];
        }

        return [
            'requires_attention' => true,
            'attention_reason' => 'Pago recibido con la reserva cancelada y sin disponibilidad para revivirla: reubicar o reembolsar.',
        ];
    }

    protected function shouldAutoConfirm(Reservation $reservation): bool
    {
        if ($reservation->status !== ReservationStatus::Pending) {
            return false;
        }

        if ($reservation->payment_status === PaymentStatus::Unpaid) {
            return false; // aún no cubre ni el anticipo
        }

        $settings = \App\Models\Property::query()->first()?->settings ?? [];

        return (bool) ($settings['auto_confirm_on_payment'] ?? true);
    }
}
