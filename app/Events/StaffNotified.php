<?php

namespace App\Events;

use App\Models\StaffNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso nuevo para la campana del panel. Canal privado por tenant, igual
 * que el semáforo y la bandeja: dos hoteles nunca lo comparten.
 */
class StaffNotified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $channelName;

    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(StaffNotification $notification)
    {
        $this->channelName = sprintf('tenant.%s.staff', tenant('id'));

        $this->payload = [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'url' => $notification->url,
            'user_id' => $notification->user_id,
            'at' => $notification->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channelName);
    }

    public function broadcastAs(): string
    {
        return 'staff.notified';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
