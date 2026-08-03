<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite con cada mensaje nuevo de cualquier canal. Canal privado por
 * tenant — tenant.{tenant}.inbox — para que la bandeja se entere en el
 * momento en vez de preguntar cada pocos segundos "¿hay algo nuevo?".
 *
 * Es una señal, no el contenido: la bandeja recarga su lista al recibirla.
 * Así el permiso de quien mira lo sigue resolviendo el servidor y el evento
 * nunca filtra mensajes a una pantalla que no debería verlos.
 */
class ConversationActivity implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $channelName;

    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(Message $message)
    {
        // Valores escalares al construir: el broadcast corre en el worker y
        // no debe depender del contexto de la petición (igual que el plano).
        $this->channelName = sprintf('tenant.%s.inbox', tenant('id'));

        $this->payload = [
            'conversation_id' => $message->conversation_id,
            'direction' => $message->direction,
            'at' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channelName);
    }

    public function broadcastAs(): string
    {
        return 'conversation.activity';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
