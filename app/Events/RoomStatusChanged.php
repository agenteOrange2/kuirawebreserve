<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite en cada transición del semáforo. Canal privado por tenant y
 * propiedad (spec §10): tenant.{tenant}.property.{id}.rooms — el plano se
 * actualiza en vivo en todas las pantallas suscritas.
 */
class RoomStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $channelName;

    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(Room $room, ?string $fromStatus, ?int $changedBy)
    {
        // Se capturan valores escalares al construir: el broadcast corre en el
        // worker de Horizon y no debe depender del contexto de la petición.
        $this->channelName = sprintf(
            'tenant.%s.property.%d.rooms',
            tenant('id'),
            $room->property_id,
        );

        $this->payload = [
            'id' => $room->id,
            'number' => $room->number,
            'property_id' => $room->property_id,
            'zone_id' => $room->zone_id,
            'from' => $fromStatus,
            'status' => $room->status->getMorphClass(),
            'color' => $room->status->color(),
            'label' => $room->status->label(),
            'transitions' => $room->status->transitionableStates(),
            'changed_by' => $changedBy,
            'changed_at' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channelName);
    }

    public function broadcastAs(): string
    {
        return 'room.status.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
