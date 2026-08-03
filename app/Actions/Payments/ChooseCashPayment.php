<?php

namespace App\Actions\Payments;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\ReservationPolicy;
use Carbon\CarbonInterface;

/**
 * El huésped eligió "pagar en el hotel" (efectivo): el apartado deja el
 * hold corto y pasa al plazo de efectivo del hotel (Plazos en
 * /ajustes/metodos-pago, default 24 h), con tope en el check-in — un
 * plazo que vence después de la llegada no aparta nada. Solo EXTIENDE
 * (mismo criterio que IssuePaymentRequest, spec-pagos §6.1): nunca
 * recorta un hold vivo. Lo usan el wizard público y el asistente IA; la
 * liberación sigue siendo del scheduler (reservations:expire-holds).
 */
class ChooseCashPayment
{
    public function handle(Reservation $reservation): ?CarbonInterface
    {
        $deadline = now()->addMinutes(app(ReservationPolicy::class)->cashDeadlineMinutes());

        if ($reservation->starts_at !== null && $reservation->starts_at->lt($deadline)) {
            $deadline = $reservation->starts_at;
        }

        if (
            $reservation->status === ReservationStatus::Pending
            && $reservation->hold_expires_at !== null
            && $reservation->hold_expires_at->lt($deadline)
        ) {
            // La elección queda visible para recepción en las notas (una
            // sola vez, aunque el huésped reintente).
            $notes = (string) $reservation->notes;
            $marca = 'Eligió pagar en el hotel (efectivo)';
            $reservation->update([
                'hold_expires_at' => $deadline,
                'notes' => str_contains($notes, $marca)
                    ? $reservation->notes
                    : trim($notes === '' ? $marca : $notes.' — '.$marca),
            ]);
        }

        return $reservation->fresh()->hold_expires_at;
    }
}
