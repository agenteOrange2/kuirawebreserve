<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registra un abono a la reserva (spec §7.5) y re-deriva su estado de pago.
 * La pasarela de cobro real es fase 7; esto registra pagos hechos por fuera.
 *
 * Si la reserva se había cancelado y el dinero llega después, no se rechaza:
 * se intenta revivirla (igual que el camino de la pasarela, ver
 * RegisterGatewayPayment) y el abono entra sobre la reserva viva. Solo cuando
 * ya no hay habitación que darle se rechaza, y con un motivo que dice qué
 * hacer. Caso real cabañas 2026-09-12: la huésped depositó y el sistema la
 * había cancelado; en mostrador no había forma de meter ese dinero.
 *
 * Y el pago de mostrador vale lo mismo que el verificado en /pagos: si cubre
 * el anticipo, la reserva se confirma (antes se quedaba "pendiente" hasta que
 * el barrido la confirmaba al vencer el plazo), y los cobros que ya no
 * corresponden se cancelan — un cobro por transferencia vivo por el mismo
 * dinero invitaba a aprobarlo dos veces en /pagos.
 */
class RegisterReservationPayment
{
    public function __construct(protected TransitionReservation $transition) {}

    /**
     * @param  array{amount: float|string, method: string, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(Reservation $reservation, array $data, ?User $user = null): Payment
    {
        $payment = DB::transaction(function () use ($reservation, $data, $user) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)
                && ! app(ReviveCancelledReservation::class)->handle($reservation, $user)) {
                throw new InvalidArgumentException(
                    'La reserva está cancelada y no hay habitación libre para revivirla: reábrela con fechas nuevas antes de registrar el pago.',
                );
            }

            $reservation->refresh();

            $amount = round((float) $data['amount'], 2);
            $pending = $reservation->pendingBalance();

            if ($amount <= 0) {
                throw new InvalidArgumentException('El monto debe ser mayor a cero.');
            }

            if ($amount > $pending) {
                throw new InvalidArgumentException(
                    'El abono ($'.number_format($amount, 2).') excede el pendiente ($'.number_format($pending, 2).').',
                );
            }

            $payment = $reservation->payments()->create([
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_by' => $user?->id,
                'paid_at' => now(),
                'created_at' => now(),
            ]);

            $reservation->syncPaymentStatus();

            $this->supersedeRequests($reservation->refresh(), $payment);

            if ($this->shouldAutoConfirm($reservation)) {
                try {
                    // El aviso al huésped lo manda quien registró (un solo
                    // mensaje con el pago y la confirmación juntos).
                    $this->transition->confirm($reservation, $user, notifyGuest: false);
                } catch (NoAvailabilityException $e) {
                    activity('reservation')
                        ->performedOn($reservation)
                        ->log('Pago registrado pero la reserva no se pudo confirmar: '.$e->getMessage());
                }
            }

            return $payment;
        });

        // Fuera de la transacción: el aviso al hotel no puede deshacer un
        // pago ya registrado.
        app(\App\Services\StaffAlerts::class)->paymentReceived($reservation->refresh(), $payment);

        return $payment;
    }

    /**
     * Cobros vivos que este pago dejó sin sentido: los que piden más de lo
     * que queda, el anticipo cuando ya se cubrió, y el consolidado del grupo
     * que contaba con el pendiente anterior de esta habitación.
     */
    protected function supersedeRequests(Reservation $reservation, Payment $payment): void
    {
        $pending = $reservation->pendingBalance();
        $depositCovered = $reservation->payment_status->coversDeposit();

        $stale = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->where('reservation_id', $reservation->id)
                ->when($reservation->reservation_group_id, fn ($query, $group) => $query->orWhere('reservation_group_id', $group)))
            ->get()
            ->filter(function (PaymentRequest $request) use ($pending, $depositCovered) {
                // El consolidado de un grupo cobra por varias habitaciones:
                // solo sobra cuando pide más de lo que el grupo entero debe.
                if ($request->isForGroup()) {
                    $owed = Reservation::query()
                        ->whereIn('id', array_keys($request->meta['breakdown'] ?? []))
                        ->get()
                        ->sum(fn (Reservation $member) => $member->pendingBalance());

                    return (float) $request->amount > $owed + 0.01;
                }

                return $pending <= 0
                    || (float) $request->amount > $pending + 0.01
                    || ($request->concept === PaymentRequest::CONCEPT_DEPOSIT && $depositCovered);
            });

        foreach ($stale as $request) {
            $request->update([
                'status' => PaymentRequest::STATUS_CANCELED,
                'meta' => array_merge($request->meta ?? [], [
                    'superseded_by_payment_id' => $payment->id,
                    'canceled_reason' => 'Se registró un pago de $'.number_format((float) $payment->amount, 2).' en mostrador.',
                    // Si el huésped ya había mandado comprobante, que se note
                    // en el historial de Pagos: puede ser el mismo dinero.
                    'had_receipt' => $request->getFirstMedia('receipt') !== null,
                ]),
            ]);
        }
    }

    /** Misma regla que la verificación en /pagos (RegisterGatewayPayment). */
    protected function shouldAutoConfirm(Reservation $reservation): bool
    {
        if ($reservation->status !== ReservationStatus::Pending || ! $reservation->payment_status->coversDeposit()) {
            return false;
        }

        $settings = \App\Models\Property::query()->first()?->settings ?? [];

        return (bool) ($settings['auto_confirm_on_payment'] ?? true);
    }
}
