<?php

namespace App\Services\Channels;

use App\Models\Central\MetaChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Services\Evolution\EvolutionApi;
use App\Services\Meta\MetaApi;

/**
 * Despachador de salida por canal: cada tipo tiene su transporte (Meta
 * Graph API, Evolution API, ...). El webchat no necesita push — el
 * visitante lee por polling. Punto único para bandeja y follow-ups.
 */
class OutboundMessenger
{
    public function __construct(
        protected MetaApi $meta,
        protected EvolutionApi $evolution,
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
            default => false,
        };
    }
}
