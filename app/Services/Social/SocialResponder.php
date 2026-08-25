<?php

namespace App\Services\Social;

use App\Models\Central\MetaChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\SocialComment;
use App\Services\Agent\AgentBrain;
use App\Services\Meta\MetaApi;
use App\Services\StaffNotifier;

/**
 * Decide y ejecuta qué hacer con un comentario ya guardado: clasificarlo,
 * contestar breve en público, abrir el mensaje privado y dejar la
 * conversación ligada en la bandeja.
 *
 * Reglas que no dependen de la configuración del hotel:
 * - Una queja NUNCA se responde sola: se avisa al staff y se espera.
 * - Ocultar nunca borra y siempre queda auditado.
 * - Si el clasificador no entendió, el comentario va a manos de una persona.
 */
class SocialResponder
{
    public function __construct(
        protected MetaApi $api,
        protected SocialCommentClassifier $classifier,
        protected AgentBrain $brain,
        protected StaffNotifier $notifier,
    ) {}

    public function handle(SocialComment $comment, MetaChannelLink $link): void
    {
        $settings = new SocialSettings;

        // Comentario sin texto: pasa cuando el acceso no tiene permiso de
        // lectura (Meta manda el evento recortado, sin `message`) o cuando
        // es solo una foto o un sticker. Mandarlo a la IA sería tirar una
        // llamada y, peor, clasificar el vacío como spam y ocultarlo —
        // exactamente lo que pasó con el primer comentario real de
        // motellacupula (2026-08-20).
        if (trim((string) $comment->body) === '') {
            $comment->update(['status' => SocialComment::STATUS_PENDING_STAFF]);
            $this->alertStaff($comment, 'Comentario sin texto legible');

            return;
        }

        // Filtro barato ANTES de gastar IA: palabra vetada por el hotel.
        if ($blocked = $settings->matchesBlockedWord($comment->body)) {
            $this->hide($comment, $link, "palabra bloqueada: {$blocked}");

            return;
        }

        if (! $settings->all()['activo'] || ! $this->brain->isConfigured()) {
            return; // el comentario queda como nuevo, para el staff
        }

        $result = $this->classifier->classify($comment->post, $comment);

        if (! $result) {
            $comment->update(['status' => SocialComment::STATUS_PENDING_STAFF]);
            $this->alertStaff($comment, 'Comentario sin clasificar');

            return;
        }

        $comment->update([
            'classification' => $result['clasificacion'],
            'classification_meta' => $result['meta'],
        ]);

        match ($result['clasificacion']) {
            SocialComment::CLASS_COMPLAINT => $this->handleComplaint($comment, $settings),
            SocialComment::CLASS_SPAM => $this->handleSpam($comment, $link, $settings),
            default => $this->handleAnswerable($comment, $link, $settings, $result),
        };
    }

    /**
     * Compra, pregunta o elogio: respuesta pública breve y, cuando aplica,
     * mensaje privado que abre la conversación en la bandeja.
     *
     * @param  array{clasificacion: string, respuesta_publica: string, mensaje_privado: string, meta: array<string, mixed>}  $result
     */
    protected function handleAnswerable(SocialComment $comment, MetaChannelLink $link, SocialSettings $settings, array $result): void
    {
        $classification = $result['clasificacion'];
        $answered = false;

        if ($settings->repliesPublicly($classification)) {
            // La PLANTILLA manda sobre lo que redacte la IA: esto se publica
            // en el muro del hotel, a la vista de todos, y un modelo barato
            // suelta frases rotas ("Te invito a contactarnos para de cada
            // una" — primera respuesta automática real, 2026-08-20). En
            // público pesa más ser correcto y predecible que personalizado;
            // lo personalizado va en el privado, que sí lo escribe la IA.
            $text = trim($settings->template($classification)) !== ''
                ? $settings->template($classification)
                : $result['respuesta_publica'];

            if (trim($text) !== '') {
                $replyId = $this->api->replyToComment($link, $comment->external_id, $text);

                if ($replyId) {
                    $comment->update([
                        'public_reply_text' => $text,
                        'public_reply_external_id' => $replyId,
                        'public_replied_at' => now(),
                    ]);
                    $answered = true;
                }
            }
        }

        if ($settings->sendsPrivate($classification) && $comment->canPrivateReply()) {
            $answered = $this->sendPrivateReply($comment, $link, $result['mensaje_privado']) || $answered;
        }

        $comment->update([
            'status' => $answered ? SocialComment::STATUS_ANSWERED : SocialComment::STATUS_PENDING_STAFF,
        ]);

        if (! $answered) {
            $this->alertStaff($comment, 'Comentario sin responder');
        }
    }

    /**
     * Manda el mensaje privado y liga (o crea) la conversación de la bandeja.
     *
     * El Send API devuelve el recipient_id del comentarista: es la misma
     * llave con la que el webhook de DMs arma las conversaciones, así que
     * cuando la persona conteste, su mensaje cae en este mismo hilo y lo
     * sigue atendiendo el asistente de siempre.
     */
    public function sendPrivateReply(SocialComment $comment, MetaChannelLink $link, string $message): bool
    {
        if (trim($message) === '') {
            return false;
        }

        $sent = $this->api->privateReply($link, $comment->external_id, $message);

        if (! $sent) {
            $comment->update(['private_reply_error' => 'Meta rechazó el mensaje privado']);

            return false;
        }

        $comment->update([
            'private_reply_sent_at' => now(),
            'private_reply_error' => null,
        ]);

        $recipient = $sent['recipient_id'] ?? $comment->author_external_id;

        if ($recipient) {
            $this->linkConversation($comment, $link, (string) $recipient, $message);
        }

        return true;
    }

    /**
     * Conversación de la bandeja para el comentarista: la misma llave
     * (canal + id externo del contacto) que usa el webhook de DMs, para que
     * su respuesta caiga en este hilo y no en uno nuevo.
     */
    protected function linkConversation(SocialComment $comment, MetaChannelLink $link, string $recipientId, string $message): void
    {
        $property = Property::query()->first();

        if (! $property) {
            return;
        }

        $channel = Channel::firstOrCreate(
            ['property_id' => $property->id, 'type' => $link->type],
            ['name' => $link->typeLabel(), 'mode' => 'auto', 'active' => true],
        );

        $conversation = Conversation::firstOrCreate(
            ['channel_id' => $channel->id, 'contact_phone' => $recipientId],
            [
                'contact_name' => $comment->author_name,
                'status' => Conversation::STATUS_OPEN,
                'bot_enabled' => true,
                'last_message_at' => now(),
            ],
        );

        if (! $conversation->contact_name && $comment->author_name) {
            $conversation->update(['contact_name' => $comment->author_name]);
        }

        // El mensaje privado queda en el hilo para que el staff vea con qué
        // se abrió la conversación y el bot no repita el saludo.
        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'bot',
            'body' => $message,
            'meta' => [
                'channel' => $link->type,
                'origen' => 'comentario',
                'comment_id' => $comment->external_id,
            ],
            'created_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Quien pregunta por precios en un comentario es un lead en curso.
        if ($comment->classification === SocialComment::CLASS_PURCHASE) {
            $conversation->markLead(Conversation::LEAD_QUOTING);
        }

        $comment->update(['conversation_id' => $conversation->id]);
    }

    /**
     * Queja: el bot no responde solo (spec §4.6). Se avisa al staff para que
     * conteste una persona.
     */
    protected function handleComplaint(SocialComment $comment, SocialSettings $settings): void
    {
        $comment->update(['status' => SocialComment::STATUS_PENDING_STAFF]);

        if ($settings->all()['avisar_quejas']) {
            $this->alertStaff($comment, 'Queja en redes sociales');
        }
    }

    protected function handleSpam(SocialComment $comment, MetaChannelLink $link, SocialSettings $settings): void
    {
        if ($settings->all()['moderacion_automatica']) {
            $this->hide($comment, $link, 'spam detectado por el asistente');

            return;
        }

        $comment->update(['status' => SocialComment::STATUS_PENDING_STAFF]);
    }

    /** Ocultar deja rastro y es reversible: nunca se borra un comentario. */
    public function hide(SocialComment $comment, MetaChannelLink $link, string $reason, ?int $userId = null): bool
    {
        $done = $this->api->hideComment($link, $comment->external_id, true);

        $comment->update([
            'status' => SocialComment::STATUS_HIDDEN,
            'hidden_at' => now(),
            'hidden_by' => $userId,
            'hidden_reason' => $reason,
        ]);

        return $done;
    }

    public function unhide(SocialComment $comment, MetaChannelLink $link, ?int $userId = null): bool
    {
        $done = $this->api->hideComment($link, $comment->external_id, false);

        $comment->update([
            'status' => SocialComment::STATUS_PENDING_STAFF,
            'hidden_at' => null,
            'hidden_by' => null,
            'hidden_reason' => null,
            'handled_by' => $userId,
            'handled_at' => now(),
        ]);

        return $done;
    }

    protected function alertStaff(SocialComment $comment, string $title): void
    {
        $this->notifier->notify(
            type: 'social',
            title: $title,
            body: mb_substr((string) $comment->body, 0, 140),
            url: '/redes',
            subject: $comment,
        );
    }
}
