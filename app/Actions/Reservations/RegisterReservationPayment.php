<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registra un abono a la reserva (spec §7.5) y re-deriva su estado de pago.
 * La pasarela de cobro real es fase 7; esto registra pagos hechos por fuera.
 */
class RegisterReservationPayment
{
    /**
     * @param  array{amount: float|string, method: string, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(Reservation $reservation, array $data, ?User $user = null): Payment
    {
        return DB::transaction(function () use ($reservation, $data, $user) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
                throw new InvalidArgumentException('La reserva está cancelada; no se pueden registrar pagos.');
            }

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

            return $payment;
        });
    }
}
