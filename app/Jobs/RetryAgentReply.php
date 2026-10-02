<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use App\Services\Channels\OutboundMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Segundo intento de responder cuando el proveedor de IA no contestó.
 *
 * Por qué existe: la cadena de este hotel tiene UN solo proveedor, así que
 * un pico suyo ("Prism provider ... is overloaded", segundos de duración)
 * dejaba al huésped con una persona aunque solo hubiera escrito "Hola".
 * Medido en el VPS de cabañas el 2026-09-22: de los 5 traspasos de la tarde,
 * 4 fueron esto — "Hola", "¿dónde se encuentra ubicado?" y "¿a qué hora es
 * la entrada?", preguntas que el bot contesta dormido.
 *
 * Mandar al hotel lo que es una falla NUESTRA es lo peor de los dos mundos:
 * el huésped espera a una persona y el hotel atiende a mano lo que el bot
 * sabía. Mejor esperar unos segundos y volver a intentarlo; solo si el
 * segundo intento también falla se transfiere (ahí sí no hay de otra).
 *
 * El webhook contesta INLINE, así que el envío no puede quedarse en el
 * request: este job manda el texto por el transporte del canal.
 */
class RetryAgentReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // el reintento es este job; no se reintenta a sí mismo

    public function __construct(
        public string $tenantId,
        public int $conversationId,
        public int $lastInboundId,
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
                return; // alguien del hotel ya la tomó
            }

            // ¿Ya contestó alguien (el personal, o el propio bot en la
            // corrida del mensaje siguiente)? Entonces este intento sobra.
            $yaContestado = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'out')
                ->where('id', '>', $this->lastInboundId)
                ->exists();

            if ($yaContestado) {
                return;
            }

            Log::info('Agente: segundo intento tras un pico del proveedor', [
                'conversation_id' => $conversation->id,
            ]);

            // Sin otra red abajo: si este intento tampoco saca respuesta,
            // reply() transfiere como siempre y el huésped recibe la frase
            // del traspaso por este mismo envío.
            $reply = $brain->reply($conversation, canRetryLater: false);

            if ($reply?->body && $conversation->channel?->type !== null) {
                $messenger->pushToConversation($conversation, $reply->body);
            }
        });
    }
}
