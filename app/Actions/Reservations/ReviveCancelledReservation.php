<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\AvailabilityService;

/**
 * Revive una reserva cancelada a la que después le llegó su dinero.
 *
 * Caso real cabañas 2026-09-12 (RES-2026-1718): la huésped depositó su
 * anticipo y nueve minutos después un proceso automático la canceló. Quien
 * pagó no puede quedarse sin habitación por un reloj: si su cuarto —o uno
 * igual— sigue libre, la reserva vuelve a la vida y el saldo queda pendiente.
 *
 * Devuelve false cuando ya no se puede revivir (su llegada pasó, o el cuarto
 * se vendió): eso NO se arregla solo, lo decide recepción reubicando o
 * devolviendo el dinero.
 */
class ReviveCancelledReservation
{
    public function __construct(protected AvailabilityService $availability) {}

    /**
     * @param  int  $holdMinutes  plazo corto para que la confirmación (o el
     *                            panel) la tome; el dinero ya está.
     */
    public function handle(Reservation $reservation, ?User $user = null, int $holdMinutes = 5): bool
    {
        if (! in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
            return false;
        }

        // Una llegada que ya pasó no se revive: sería apartar noches que
        // nadie va a dormir (mismo criterio que TransitionReservation::reopen).
        if ($reservation->starts_at->lt(now()->startOfDay())) {
            return false;
        }

        $room = $this->freeRoomFor($reservation);

        if ($room === null) {
            return false;
        }

        $reservation->update([
            'room_id' => $room->id,
            'status' => ReservationStatus::Pending,
            'hold_expires_at' => now()->addMinutes(max(1, $holdMinutes)),
            'cancellation_reason' => null,
        ]);

        activity('reservation')
            ->performedOn($reservation)
            ->causedBy($user)
            ->log('Reserva revivida: su pago llegó después de cancelarse');

        return true;
    }

    /** Su misma habitación si sigue libre; si ya se vendió, otra del mismo tipo. */
    protected function freeRoomFor(Reservation $reservation): ?Room
    {
        if ($reservation->room_id) {
            $room = Room::query()->whereKey($reservation->room_id)->lockForUpdate()->first();

            if ($room && $this->availability->isRoomAvailable(
                $room,
                $reservation->starts_at,
                $reservation->ends_at,
                $reservation->id,
            )) {
                return $room;
            }
        }

        return $this->availability
            ->availableRooms(
                $reservation->room_type_id,
                $reservation->starts_at,
                $reservation->ends_at,
                $reservation->id,
                lock: true,
            )
            ->first();
    }
}
