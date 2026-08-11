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
 * con hold vigente) ni con estancias activas, no está en mantenimiento, no
 * tiene un bloqueo por fechas (RoomBlock) sobre las noches pedidas y no tiene
 * el candado por usos activo (usage_locked_at, rotación de habitaciones).
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
        // Rotación por desgaste parejo: la auto-asignación toma primero la
        // habitación con menos usos (antes siempre ganaba el número más bajo
        // y la 101 se llevaba todos los walk-ins); el número desempata.
        $query = $this->availabilityQuery($start, $end, $ignoreReservationId)
            ->where('room_type_id', $roomTypeId)
            ->orderBy('usage_count')
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

        // Candado por usos: alcanzó su límite y nadie la puede asignar
        // (ni el wizard ni recepción) hasta resetear el contador.
        if ($room->usageLocked()) {
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

        // Bloqueo por fechas (mantenimiento programado): los días marcados
        // no se venden, aunque el semáforo presente siga en disponible.
        if ($room->blocks()->overlapping($start, $end)->exists()) {
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
            // Candado por usos (rotación): bloqueada hasta resetear contador.
            ->whereNull('usage_locked_at')
            ->whereDoesntHave('reservations', function (Builder $q) use ($start, $end, $ignoreReservationId) {
                $q->blocking()
                    ->overlapping($start, $end)
                    ->when($ignoreReservationId, fn (Builder $qq) => $qq->whereKeyNot($ignoreReservationId));
            })
            ->whereDoesntHave('stays', fn (Builder $q) => $q->active()->overlapping($start, $end))
            // Bloqueos por fechas: la habitación no se ofrece si algún
            // bloqueo pisa las noches pedidas (panel, wizard y agentes
            // pasan todos por esta query).
            ->whereDoesntHave('blocks', fn (Builder $q) => $q->overlapping($start, $end));
    }
}
