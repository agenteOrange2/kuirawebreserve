<?php

namespace App\Services\Channels;

use App\Models\Central\MetaChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Services\Evolution\EvolutionApi;
use App\Services\Meta\MetaApi;
use App\Services\Telegram\TelegramApi;
use App\Services\Tiktok\TiktokApi;

/**
 * Despachador de salida por canal: cada tipo tiene su transporte (Meta
 * Graph API, Evolution API, Bot API de Telegram, Business API de TikTok).
 * El webchat no necesita push — el visitante lee por polling. Punto único
 * para bandeja y follow-ups.
 */
class OutboundMessenger
{
    public function __construct(
        protected MetaApi $meta,
        protected EvolutionApi $evolution,
        protected TelegramApi $telegram,
        protected TiktokApi $tiktok,
    ) {}

    /**
     * @param  int|null  $delayMs  Retraso humanizado (solo aplica en Evolution;
     *                             la Cloud API oficial no lo necesita).
     */
    public function pushToConversation(Conversation $conversation, string $text, ?int $delayMs = null): bool
    {
        $type = $conversation->channel?->type;

        return match (true) {
            in_array($type, MetaChannelLink::TYPES, true) => $this->meta->pushToConversation($conversation, $text),
            $type === Channel::TYPE_WHATSAPP_EVOLUTION => $this->evolution->pushToConversation($conversation, $text, $delayMs),
            $type === Channel::TYPE_TELEGRAM => $this->telegram->pushToConversation($conversation, $text),
            $type === Channel::TYPE_TIKTOK => $this->tiktok->pushToConversation($conversation, $text),
            default => false,
        };
    }

    /**
     * El canal RECHAZÓ la respuesta: fuera de la ventana de 24 h, número
     * inválido, token vencido o la instancia caída. Hasta hoy eso solo
     * dejaba una línea en el log — la bandeja mostraba el mensaje como
     * enviado y el huésped nunca lo recibía (cabañas 2026-09-15, conv. 830:
     * una respuesta de 4,457 caracteres que WhatsApp rechazó entera).
     */
    public function flagUndelivered(Conversation $conversation, ?\App\Models\Message $message = null): void
    {
        try {
            if ($message !== null) {
                $message->forceFill([
                    'meta' => array_merge($message->meta ?? [], ['undelivered' => true]),
                ])->saveQuietly();
            }

            if ($conversation->status !== Conversation::STATUS_PENDING) {
                $conversation->update(['status' => Conversation::STATUS_PENDING]);
            }

            app(\App\Services\StaffNotifier::class)->notify(
                type: \App\Models\StaffNotification::TYPE_MESSAGE,
                title: 'Respuesta no entregada',
                body: ($conversation->guest?->full_name ?? $conversation->contact_name ?? 'Un huésped')
                    .' no recibió la respuesta del asistente: '
                    .\Illuminate\Support\Str::limit(trim((string) $message?->body), 80)
                    .' Contéstale tú por el canal.',
                url: '/bandeja?conversation='.$conversation->id,
                subject: $conversation,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Adjunto saliente (foto o PDF que manda el staff). El webchat no tiene
     * transporte: el visitante lo verá al recargar su hilo.
     */
    public function pushMediaToConversation(
        Conversation $conversation,
        string $path,
        string $mime,
        string $fileName,
        ?string $caption = null,
    ): bool {
        $type = $conversation->channel?->type;

        return match (true) {
            in_array($type, MetaChannelLink::TYPES, true) => $this->meta->pushMediaToConversation($conversation, $path, $mime, $fileName, $caption),
            $type === Channel::TYPE_WHATSAPP_EVOLUTION => $this->evolution->pushMediaToConversation($conversation, $path, $mime, $fileName, $caption),
            $type === Channel::TYPE_TELEGRAM => $this->telegram->pushMediaToConversation($conversation, $path, $mime, $fileName, $caption),
            // TikTok no acepta adjuntos salientes por la Business API.
            default => false,
        };
    }
}
