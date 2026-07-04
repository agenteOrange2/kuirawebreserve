<?php

namespace App\Services\Meta;

use App\Models\Central\MetaChannelLink;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío saliente por la Graph API de Meta. Un solo punto de salida para
 * bot, staff (bandeja) y futuros follow-ups. Nunca lanza: si Meta falla,
 * el mensaje queda en la conversación y se reporta el error.
 */
class MetaApi
{
    public function sendText(MetaChannelLink $link, string $to, string $text): bool
    {
        $graph = rtrim(config('meta.graph_url'), '/');

        try {
            $response = match ($link->type) {
                // WhatsApp Cloud API: POST /{phone_number_id}/messages
                'whatsapp' => Http::withToken($link->access_token)
                    ->post("{$graph}/{$link->external_id}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to' => $to,
                        'type' => 'text',
                        'text' => ['body' => $text],
                    ]),
                // Messenger / Instagram: Send API con el token de la página
                default => Http::withToken($link->access_token)
                    ->post("{$graph}/me/messages", [
                        'recipient' => ['id' => $to],
                        'messaging_type' => 'RESPONSE',
                        'message' => ['text' => $text],
                    ]),
            };

            if ($response->failed()) {
                Log::warning('Meta: envío fallido', [
                    'type' => $link->type,
                    'tenant' => $link->tenant_id,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Envía a la persona detrás de una conversación de canal Meta (el id
     * externo del contacto vive en contact_phone). Para webchat u otros
     * canales no hace nada: el visitante lee por polling.
     */
    public function pushToConversation(Conversation $conversation, string $text): bool
    {
        $type = $conversation->channel?->type;

        if (! in_array($type, MetaChannelLink::TYPES, true) || ! tenant() || ! $conversation->contact_phone) {
            return false;
        }

        $link = MetaChannelLink::query()
            ->where('tenant_id', tenant('id'))
            ->where('type', $type)
            ->where('active', true)
            ->first();

        return $link ? $this->sendText($link, $conversation->contact_phone, $text) : false;
    }
}
