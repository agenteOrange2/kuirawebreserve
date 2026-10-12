<?php

namespace App\Actions\Rooms;

use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Models\Room;
use App\Models\RoomCleaning;
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

        // Contador de usos: la habitación "se usó" cuando queda por limpiar
        // tras una estancia (ocupada → sucia) o tras un cierre de día con uso
        // asumido (reservada vencida → sucia). Check-out manual, auto-checkout,
        // cambio de habitación y cierre de día pasan todos por aquí, así que
        // el incremento vive en un solo lugar. Query builder (como el
        // used_count de cupones) para que sea atómico y sin eventos de modelo.
        if ($toStatus === RoomStatus::Dirty->value
            && in_array($from, [RoomStatus::Occupied->value, RoomStatus::Reserved->value], true)) {
            Room::query()->whereKey($room->id)->increment('usage_count');
            $room->usage_count = (int) Room::query()->whereKey($room->id)->value('usage_count');

            app(SyncRoomUsageLock::class)->handle($room, $changedBy, array_filter([
                'auto' => (bool) ($context['auto'] ?? false),
            ]));
        }

        // Una habitación que sale de "en limpieza" sin que nadie terminara el
        // registro (el reloj la liberó, alguien la mandó a mantenimiento)
        // cierra aquí su limpieza abierta. Antes el cronómetro corría para
        // siempre y la siguiente limpieza de ese cuarto se rechazaba por "ya
        // tiene una en curso". Quien la termina con el formulario la cierra
        // ANTES de mover el semáforo, así que aquí ya no encuentra nada.
        if ($from === RoomStatus::Cleaning->value
            && $toStatus !== RoomStatus::Cleaning->value
            && empty($context['cleaning_id'])) {
            RoomCleaning::closeOpenFor(
                $room,
                ($context['auto'] ?? false) ? RoomCleaning::CLOSED_BY_CLOCK : RoomCleaning::CLOSED_BY_STATUS,
            );
        }

        RoomStatusChanged::dispatch($room, $from, $changedBy?->id);

        return $room;
    }
}
