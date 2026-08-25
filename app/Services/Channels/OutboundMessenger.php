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
