<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use App\Services\Channels\OutboundMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Devolver al bot" con respuesta inmediata (pedido del dueño, 2026-10-08).
 *
 * Antes devolver la conversación solo encendía el interruptor: el bot no
 * decía nada hasta que el huésped volviera a escribir, aunque su último
 * mensaje siguiera sin respuesta (el bot pasó a una persona, nadie contestó,
 * el huésped quedó esperando). Ahora, si lo último que hay es del huésped,
 * el bot lo contesta en cuanto se le devuelve; y el personal puede pedirle
 * que escriba aunque lo último haya sido del hotel.
 *
 * Los avisos del sistema ("Te comunicamos con una persona…") no cuentan
 * como respuesta: son justo el caso en que el huésped quedó esperando.
 * Corre en la cola porque el bot tarda segundos y el clic no debe esperar.
 */
class ResumeBotReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public int $conversationId,
        // Último mensaje que vio quien devolvió la conversación: si alguien
        // escribe algo después, esta respuesta ya sobra.
        public int $afterMessageId,
    ) {}

    public function handle(AgentBrain $brain, OutboundMessenger $messenger): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $tenant->run(function () use ($brain, $messenger) {
            $conversation = Conversation::find($this->conversationId);

            if (! $conversation || ! $conversation->bot_enabled) {
                return; // alguien la volvió a tomar
            }

            $alreadyAnswered = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'out')
                ->where('sender_type', '!=', 'system')
                ->where('id', '>', $this->afterMessageId)
                ->exists();

            if ($alreadyAnswered) {
                return;
            }

            $reply = $brain->reply($conversation, canRetryLater: false);

            if ($reply?->body && $conversation->channel?->type !== null) {
                $messenger->pushToConversation($conversation, $reply->body);
            }
        });
    }

    /**
     * ¿El huésped espera respuesta? Lo último que hay, sin contar los avisos
     * del sistema, es un mensaje suyo.
     */
    public static function guestIsWaiting(Conversation $conversation): bool
    {
        $last = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->where('direction', 'in')->orWhere('sender_type', '!=', 'system'))
            ->orderByDesc('id')
            ->first();

        return $last?->direction === 'in';
    }
}
