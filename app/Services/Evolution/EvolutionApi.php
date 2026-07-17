<?php

namespace App\Services\Evolution;

use App\Models\Central\EvolutionChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío saliente por Evolution API (WhatsApp self-hosted) — el equivalente
 * de MetaApi para el tipo de canal whatsapp_evo. Nunca lanza: si la
 * instancia falla, el mensaje queda en la conversación y se reporta.
 */
class EvolutionApi
{
    /**
     * Retraso humanizado antes de entregar (anti-ban): responder en cero
     * segundos con textos largos es firma clásica de bot. Con `delay`,
     * Evolution muestra "escribiendo..." ese tiempo antes de enviar.
     */
    public const DELAY_MIN_MS = 2000;

    public const DELAY_MAX_MS = 7000;

    /**
     * Retraso proporcional al largo del texto (como si se tecleara), con
     * variación aleatoria para no ser un metrónomo.
     */
    public static function humanDelay(string $text): int
    {
        $base = 1500 + (int) (mb_strlen($text) * 35);
        $jitter = random_int(80, 120) / 100;

        return (int) max(self::DELAY_MIN_MS, min(self::DELAY_MAX_MS, $base * $jitter));
    }

    /**
     * @param  int|null  $delayMs  Retraso con presencia "escribiendo..." antes
     *                             de entregar. Solo mensajes del bot: las
     *                             respuestas del staff ya tienen ritmo humano.
     */
    public function sendText(EvolutionChannelLink $link, string $to, string $text, ?int $delayMs = null): bool
    {
        try {
            // Evolution API v2: POST /message/sendText/{instance}. El server
            // espera el delay ANTES de responder el HTTP: el timeout lo cubre.
            $response = $this->http($link, $delayMs)->post(
                $this->url($link, "/message/sendText/{$link->instance}"),
                array_filter([
                    'number' => $to,
                    'text' => $text,
                    'delay' => $delayMs,
                ], fn ($value) => $value !== null),
            );

            // Instalaciones v1.x usan el payload anidado en textMessage.
            if ($response->status() === 400) {
                $response = $this->http($link, $delayMs)->post(
                    $this->url($link, "/message/sendText/{$link->instance}"),
                    array_filter([
                        'number' => $to,
                        'textMessage' => ['text' => $text],
                        'options' => $delayMs !== null ? ['delay' => $delayMs, 'presence' => 'composing'] : null,
                    ], fn ($value) => $value !== null),
                );
            }

            if ($response->failed()) {
                Log::warning('Evolution: envío fallido', [
                    'link_id' => $link->id,
                    'tenant' => $link->tenant_id,
                    'instance' => $link->instance,
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
     * Envía a la persona detrás de una conversación de un canal Evolution.
     * La instancia exacta se resuelve por channels.external_id (id del link
     * central), así cada conversación sale por el número que la recibió.
     */
    public function pushToConversation(Conversation $conversation, string $text, ?int $delayMs = null): bool
    {
        $channel = $conversation->channel;

        if ($channel?->type !== Channel::TYPE_WHATSAPP_EVOLUTION || ! $conversation->contact_phone) {
            return false;
        }

        $link = EvolutionChannelLink::query()
            ->whereKey($channel->external_id)
            ->where('active', true)
            ->first();

        return $link ? $this->sendText($link, $conversation->contact_phone, $text, $delayMs) : false;
    }

    /**
     * Estado de conexión de la instancia (¿el número está vinculado?).
     * Solo cuenta como ok si la respuesta trae un estado real: una URL mal
     * apuntada (p. ej. al /manager) devuelve HTML con 200 y NO es conexión.
     *
     * @return array{ok: bool, state: string|null}
     */
    public function connectionState(EvolutionChannelLink $link): array
    {
        try {
            $response = $this->http($link)->get(
                $this->url($link, "/instance/connectionState/{$link->instance}"),
            );

            if ($response->failed()) {
                return ['ok' => false, 'state' => null];
            }

            $json = $response->json();
            $state = $json['instance']['state'] ?? $json['state'] ?? null;

            return ['ok' => $state !== null, 'state' => $state];
        } catch (Throwable) {
            return ['ok' => false, 'state' => null];
        }
    }

    /**
     * Configura el webhook de la instancia hacia nuestra plataforma (solo
     * eventos de mensajes). Si la versión de Evolution no acepta el formato,
     * el panel muestra la URL para configurarlo a mano.
     */
    public function configureWebhook(EvolutionChannelLink $link): bool
    {
        // Evolution API v2.x: envoltura "webhook" con byEvents/base64.
        $payloadV2 = [
            'webhook' => [
                'enabled' => true,
                'url' => $link->webhookUrl(),
                'byEvents' => false,
                'base64' => false,
                'events' => ['MESSAGES_UPSERT'],
            ],
        ];

        try {
            $response = $this->http($link)->post(
                $this->url($link, "/webhook/set/{$link->instance}"),
                $payloadV2,
            );

            // v1.x espera el payload plano.
            if ($response->failed()) {
                $response = $this->http($link)->post(
                    $this->url($link, "/webhook/set/{$link->instance}"),
                    [
                        'enabled' => true,
                        'url' => $link->webhookUrl(),
                        'webhook_by_events' => false,
                        'webhook_base64' => false,
                        'events' => ['MESSAGES_UPSERT'],
                    ],
                );
            }

            if ($response->failed()) {
                Log::warning('Evolution: no se pudo configurar el webhook', [
                    'link_id' => $link->id,
                    'instance' => $link->instance,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function http(EvolutionChannelLink $link, ?int $delayMs = null): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['apikey' => $link->api_key])
            ->timeout(10 + (int) ceil(($delayMs ?? 0) / 1000))
            ->acceptJson();
    }

    protected function url(EvolutionChannelLink $link, string $path): string
    {
        return rtrim($link->base_url, '/').$path;
    }
}
