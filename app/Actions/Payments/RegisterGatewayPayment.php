<?php

namespace App\Actions\Payments;

use App\Actions\Reservations\ReviveCancelledReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Exceptions\PaymentNeedsConfirmation;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\User;
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
        protected TransitionReservation $transition,
        protected ReviveCancelledReservation $revive,
    ) {}

    /**
     * @param  array{reference?: string|null, notes?: string|null, gateway?: string|null, gateway_ref?: string|null, fee_amount?: float|null}  $data
     */
    public function handle(PaymentRequest $request, array $data = [], ?User $verifier = null): Payment
    {
        $payment = DB::transaction(function () use ($request, $data, $verifier) {
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

            // Una PERSONA aprobando una transferencia (las pasarelas y sus
            // webhooks no pasan por aquí: ese dinero ya entró). Caso real
            // cabañas 2026-09-15: se capturaron a mano cinco transferencias
            // y luego se aprobaron sus comprobantes en /pagos; cada
            // aprobación creó OTRO pago por el mismo dinero. Iris quedó
            // "pagada" debiendo $1,750 y el corte subió $7,500 de aire.
            if ($verifier !== null && $request->method === PaymentRequest::METHOD_TRANSFER) {
                // El folio o la clave de rastreo de este comprobante ya está
                // en un pago registrado. Caso real cabañas 2026-09-27
                // (reserva 1789): la huésped pagó por Mercado Pago, mandó
                // la captura de ESE pago y se aprobó como saldo; quedó
                // "Pagada" con $1,750 que nunca entraron.
                if (empty($data['confirm_overpay']) && ($twin = $this->paymentWithSameFolio($request, $data))) {
                    throw new PaymentNeedsConfirmation(sprintf(
                        'El folio %s ya está registrado en un pago de $%s (%s, %s)%s. Es el mismo dinero: no apruebes este comprobante, recházalo. Solo si de verdad es otra operación, confírmalo.',
                        $twin['folio'],
                        number_format((float) $twin['payment']->amount, 2),
                        mb_strtolower(Payment::methodLabel($twin['payment']->method)),
                        $twin['payment']->created_at->format('d/m/Y H:i'),
                        $twin['payment']->reservation_id && $twin['payment']->reservation_id !== $request->reservation_id
                            ? ' de '.$twin['payment']->reservation?->displayCode()
                            : ' de esta misma reserva',
                    ), confirmLabel: 'Sí, es otra operación');
                }

                $depositAlreadyCovered = $request->concept === PaymentRequest::CONCEPT_DEPOSIT
                    && $reservation->payment_status->coversDeposit();
                $exceedsPending = (float) $request->amount > $reservation->pendingBalance() + 0.01;

                if ($depositAlreadyCovered || $exceedsPending) {
                    // Lo más probable: es el mismo depósito que ya se capturó.
                    if ($captured = $this->capturedTransfer($reservation, $request)) {
                        return $this->linkCaptured($request, $captured);
                    }

                    if (empty($data['confirm_overpay'])) {
                        throw new PaymentNeedsConfirmation(sprintf(
                            'Esta reserva ya tiene registrados $%s de $%s%s. Aprobar este comprobante sumaría $%s más. Si es el mismo depósito que ya se capturó, no lo apruebes; si de verdad entró dinero de más, confírmalo.',
                            number_format($reservation->paidTotal(), 2),
                            number_format((float) $reservation->total_amount, 2),
                            $depositAlreadyCovered ? ' y el anticipo ya está cubierto' : '',
                            number_format((float) $request->amount, 2),
                        ));
                    }
                } elseif (empty($data['confirm_overpay'])
                    && ($handTwin = $this->capturedByHand($reservation, $request))) {
                    // Caso real cabañas 2026-10-03 (reserva 1789): el anticipo
                    // se capturó a mano y su comprobante se aprobó como cobro
                    // de SALDO por el mismo monto (con 50 % de anticipo valen
                    // igual). Nada de lo de arriba lo detenía y la reserva
                    // quedó "Pagada" con el dinero doble. Aquí no se liga
                    // solo: puede ser el saldo de verdad, así que se pregunta.
                    throw new PaymentNeedsConfirmation(sprintf(
                        'El %s ya se capturó a mano un pago de $%s (%s) en esta reserva. Si este comprobante es ese mismo dinero, no lo apruebes: recházalo. Si es otro pago, confírmalo.',
                        $handTwin->created_at->format('d/m/Y \\a \\l\\a\\s H:i'),
                        number_format((float) $handTwin->amount, 2),
                        mb_strtolower(Payment::methodLabel($handTwin->method)),
                    ), confirmLabel: 'Sí, es otro pago');
                }
            }

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

        // Aviso al hotel, fuera de la transacción y solo por dinero NUEVO: un
        // webhook repetido o un comprobante que se ligó a lo ya capturado
        // devuelven el pago de antes. Los tours no avisan aquí; un cobro de
        // grupo avisa una vez, por el monto completo.
        if ($payment->wasRecentlyCreated && $payment->reservation_id) {
            $request->refresh();

            app(\App\Services\StaffAlerts::class)->paymentReceived(
                $payment->reservation,
                $payment,
                amount: $request->isForGroup() ? (float) $request->amount : null,
            );
        }

        return $payment;
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

        // Misma regla que una reserva suelta: si una persona aprueba una
        // transferencia que suma más de lo que el grupo aún debe, se pide
        // confirmación antes de registrar dinero de más.
        if ($verifier !== null && $request->method === PaymentRequest::METHOD_TRANSFER && empty($data['confirm_overpay'])) {
            $owed = Reservation::query()
                ->whereIn('id', array_keys($breakdown))
                ->get()
                ->sum(fn (Reservation $member) => $member->pendingBalance());
            $shares = array_sum(array_map('floatval', $breakdown));

            if ($shares > $owed + 0.01) {
                throw new PaymentNeedsConfirmation(sprintf(
                    'Al grupo solo le faltan $%s y este comprobante registraría $%s. Si es un depósito que ya se capturó, no lo apruebes; si de verdad entró dinero de más, confírmalo.',
                    number_format($owed, 2),
                    number_format($shares, 2),
                ));
            }
        }

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
     * La transferencia que ya se capturó a mano por este mismo dinero: mismo
     * monto, sin comprobante ligado y registrada desde un día antes de que
     * naciera el cobro. Si el cobro se canceló por ese pago, ese va primero.
     */
    protected function capturedTransfer(Reservation $reservation, PaymentRequest $request): ?Payment
    {
        $supersededBy = (int) ($request->meta['superseded_by_payment_id'] ?? 0);

        return $reservation->payments()
            ->whereNull('payment_request_id')
            ->where('method', 'transfer')
            ->where('amount', $request->amount)
            ->where('created_at', '>=', $request->created_at->copy()->subDay())
            ->get()
            ->sortBy(fn (Payment $payment) => [$payment->id === $supersededBy ? 0 : 1, $payment->id])
            ->first();
    }

    /**
     * El pago que ya trae el folio de este comprobante: la clave de rastreo
     * que leyó el lector o el folio que tecleó quien verifica. Se busca en
     * `reference` y en `gateway_ref` (pasarela). Folios cortos no cuentan:
     * "13:11" o "1" no identifican una operación.
     *
     * @param  array<string, mixed>  $data
     * @return array{folio: string, payment: Payment}|null
     */
    protected function paymentWithSameFolio(PaymentRequest $request, array $data): ?array
    {
        $folios = collect([
            $request->meta['tracking_key'] ?? null,
            $request->meta['receipt_reading']['tracking_key'] ?? null,
            $data['reference'] ?? null,
        ])
            ->map(fn ($folio) => trim((string) $folio))
            ->filter(fn (string $folio) => strlen($folio) >= 8 && preg_match('/\d{6,}/', $folio))
            ->unique()
            ->values();

        foreach ($folios as $folio) {
            $payment = Payment::query()
                ->where(fn ($query) => $query->where('reference', $folio)->orWhere('gateway_ref', $folio))
                ->where(fn ($query) => $query->whereNull('payment_request_id')->orWhere('payment_request_id', '!=', $request->id))
                ->with('reservation')
                ->oldest('id')
                ->first();

            if ($payment) {
                return ['folio' => $folio, 'payment' => $payment];
            }
        }

        return null;
    }

    /**
     * Un pago capturado a mano (cualquier método) por el mismo monto y en la
     * misma ventana que capturedTransfer: el candidato a ser este mismo
     * dinero. Solo los abonos de la reserva, no los cobros del folio.
     */
    protected function capturedByHand(Reservation $reservation, PaymentRequest $request): ?Payment
    {
        return $reservation->payments()
            ->whereNull('payment_request_id')
            ->whereNull('kind')
            ->whereIn('method', Payment::METHODS)
            ->where('amount', $request->amount)
            ->where('created_at', '>=', $request->created_at->copy()->subDay())
            ->oldest('id')
            ->first();
    }

    /** El comprobante se cierra con el pago que ya existía: no se crea otro. */
    protected function linkCaptured(PaymentRequest $request, Payment $payment): Payment
    {
        $payment->update(['payment_request_id' => $request->id]);

        $request->update([
            'status' => PaymentRequest::STATUS_PAID,
            'payment_id' => $payment->id,
            'meta' => array_merge($request->meta ?? [], ['linked_to_captured_payment' => $payment->id]),
        ]);

        activity('payment')
            ->performedOn($payment)
            ->log('Comprobante ligado al pago que ya estaba capturado en mostrador: no se duplicó el dinero.');

        return $payment;
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviveOrFlag(Reservation $reservation, ?User $user): array
    {
        // Una sola implementación de "llegó el dinero de una cancelada"
        // (ReviveCancelledReservation), compartida con el registro de
        // mostrador: intenta su misma habitación y, si se vendió, otra del
        // mismo tipo. confirm() la lleva después a Confirmada.
        if ($this->revive->handle($reservation, $user)) {
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

        if (! $reservation->payment_status->coversDeposit()) {
            return false; // aún no cubre ni el anticipo
        }

        $settings = \App\Models\Property::query()->first()?->settings ?? [];

        return (bool) ($settings['auto_confirm_on_payment'] ?? true);
    }
}
