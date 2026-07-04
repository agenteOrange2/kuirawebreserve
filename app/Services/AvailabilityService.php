<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Models\Room;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Motor de disponibilidad (spec §7). Una habitación está libre en un rango si
 * no tiene solape con reservas bloqueantes (confirmadas/en casa, o pendientes
 * con hold vigente) ni con estancias activas, y no está en mantenimiento.
 *
 * Anti-doble-reserva: dentro de una transacción, con $lock=true el SELECT es
 * FOR UPDATE — bloquea las filas de habitaciones candidatas y lee el último
 * estado confirmado, serializando a los competidores por la misma habitación.
 */
class AvailabilityService
{
    /**
     * @return Collection<int, Room>
     */
    public function availableRooms(
        int $roomTypeId,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $ignoreReservationId = null,
        bool $lock = false,
    ): Collection {
        $query = $this->availabilityQuery($start, $end, $ignoreReservationId)
            ->where('room_type_id', $roomTypeId)
            ->orderBy('number');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Verifica una habitación específica. Llamar dentro de una transacción
     * con la fila de la habitación ya bloqueada (lockForUpdate) para que la
     * lectura sea serializada.
     */
    public function isRoomAvailable(
        Room $room,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $ignoreReservationId = null,
    ): bool {
        if ($room->status->getMorphClass() === RoomStatus::Maintenance->value) {
            return false;
        }

        $blockedByReservation = $room->reservations()
            ->blocking()
            ->overlapping($start, $end)
            ->when($ignoreReservationId, fn (Builder $q) => $q->whereKeyNot($ignoreReservationId))
            ->lockForUpdate()
            ->first() !== null;

        if ($blockedByReservation) {
            return false;
        }

        return $room->stays()
            ->active()
            ->overlapping($start, $end)
            ->lockForUpdate()
            ->first() === null;
    }

    protected function availabilityQuery(
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $ignoreReservationId = null,
    ): Builder {
        return Room::query()
            ->where('status', '!=', RoomStatus::Maintenance->value)
            ->whereDoesntHave('reservations', function (Builder $q) use ($start, $end, $ignoreReservationId) {
                $q->blocking()
                    ->overlapping($start, $end)
                    ->when($ignoreReservationId, fn (Builder $qq) => $qq->whereKeyNot($ignoreReservationId));
            })
            ->whereDoesntHave('stays', fn (Builder $q) => $q->active()->overlapping($start, $end));
    }
}
