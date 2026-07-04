<?php

namespace App\Actions\Rooms;

use App\Events\RoomStatusChanged;
use App\Models\Room;
use App\Models\User;
use App\States\Room\RoomState;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * Punto único para mover el semáforo de una habitación: valida la transición
 * con la máquina de estados, deja rastro en room_status_logs y emite el
 * broadcast. Lo usan el panel y, en fase 4, los agentes IA.
 */
class ChangeRoomStatus
{
    /**
     * @param  array<string, mixed>  $context
     *
     * @throws CouldNotPerformTransition
     */
    public function handle(Room $room, string $toStatus, ?User $changedBy = null, array $context = []): Room
    {
        $from = $room->status->getMorphClass();

        $stateClass = RoomState::resolveStateClass($toStatus);

        $room->status->transitionTo($stateClass);

        $room->statusLogs()->create([
            'from_status' => $from,
            'to_status' => $toStatus,
            'changed_by' => $changedBy?->id,
            'context' => $context ?: null,
            'created_at' => now(),
        ]);

        RoomStatusChanged::dispatch($room, $from, $changedBy?->id);

        return $room;
    }
}
