<?php

namespace App\Services\Tiktok;

use App\Models\Central\TiktokChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío saliente por la Business Messaging API de TikTok (DMs de la cuenta
 * business). Requiere una app aprobada por TikTok con el scope de
 * mensajería; el access token se captura por hotel. Nunca lanza: si TikTok
 * falla, el mensaje queda en la conversación y se reporta.
 */
class TiktokApi
{
    public const BASE_URL = 'https://business-api.tiktok.com/open_api/v1.3';

    public function sendText(TiktokChannelLink $link, string $toUserId, string $text): bool
    {
        try {
            $response = $this->http($link)->post(self::BASE_URL.'/business/message/send/', [
                'business_id' => $link->business_id,
                'recipient_id' => $toUserId,
                'message' => ['type' => 'text', 'text' => $text],
            ]);

            // La Business API responde 200 con code != 0 cuando algo falla.
            if ($response->failed() || (int) $response->json('code', -1) !== 0) {
                Log::warning('TikTok: envío fallido', [
                    'link_id' => $link->id,
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
     * Prueba de la conexión: consulta la cuenta business con el token
     * capturado. Solo cuenta como ok si TikTok responde con code 0.
     *
     * @return array{ok: bool, name: string|null}
     */
    public function accountInfo(TiktokChannelLink $link): array
    {
        try {
            $response = $this->http($link)->get(self::BASE_URL.'/business/get/', [
                'business_id' => $link->business_id,
                'fields' => json_encode(['username', 'display_name']),
            ]);

            if ($response->failed() || (int) $response->json('code', -1) !== 0) {
                return ['ok' => false, 'name' => null];
            }

            $data = $response->json('data', []);

            return ['ok' => true, 'name' => $data['display_name'] ?? $data['username'] ?? null];
        } catch (Throwable) {
            return ['ok' => false, 'name' => null];
        }
    }

    /**
     * Envía a la persona detrás de una conversación de un canal TikTok.
     * La cuenta exacta se resuelve por channels.external_id (id del link
     * central), igual que Evolution/Telegram.
     */
    public function pushToConversation(Conversation $conversation, string $text): bool
    {
        $channel = $conversation->channel;

        if ($channel?->type !== Channel::TYPE_TIKTOK || ! $conversation->contact_phone) {
            return false;
        }

        $link = TiktokChannelLink::query()
            ->whereKey($channel->external_id)
            ->where('active', true)
            ->first();

        return $link ? $this->sendText($link, $conversation->contact_phone, $text) : false;
    }

    protected function http(TiktokChannelLink $link): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['Access-Token' => $link->access_token])
            ->timeout(15)
            ->acceptJson();
    }
}
