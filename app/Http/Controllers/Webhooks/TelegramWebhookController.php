<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Central\TelegramChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\Request;

/**
 * Receptor de webhooks de la Bot API de Telegram (dominio central, sin
 * sesión/CSRF). Cada bot apunta a /webhooks/telegram/{token}; el token
 * identifica el vínculo (y con él al tenant). A partir de ahí el camino es
 * el mismo que Meta/Evolution: conversación + mensaje + bot IA si el canal
 * está en automático.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(protected TelegramApi $api) {}

    public function receive(Request $request, string $token)
    {
        $link = TelegramChannelLink::query()
            ->where('webhook_token', $token)
            ->where('active', true)
            ->first();

        if (! $link) {
            return response()->json(['ok' => false], 404);
        }

        // Latido del canal (cualquier update cuenta como señal de vida).
        $link->forceFill(['last_event_at' => now()])->saveQuietly();

        $inbound = self::extractMessage($request->json()->all());

        if ($inbound !== null) {
            $this->handleInbound($link, ...$inbound);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Normaliza un update de Telegram a mensaje entrante. Solo chats
     * privados (los grupos quedan fuera del flujo de reservas); foto y
     * documento viajan en `media` con su file_id para bajarlos después.
     *
     * @param  array<string, mixed>  $payload
     * @return array{from: string, name: string|null, body: string, externalId: string|null, media: array{file_id: string, mime: string|null, filename: string|null}|null}|null
     */
    public static function extractMessage(array $payload): ?array
    {
        $message = $payload['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');

        if ($chatId === '' || ($chat['type'] ?? 'private') !== 'private') {
            return null;
        }

        $from = $message['from'] ?? [];
        // Los bots no reciben sus propios mensajes por webhook, pero por si
        // acaso: cualquier emisor bot queda fuera.
        if (! empty($from['is_bot'])) {
            return null;
        }

        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''))
            ?: ($from['username'] ?? null);

        $body = $message['text'] ?? $message['caption'] ?? null;
        $media = null;

        if (isset($message['photo']) || isset($message['document'])) {
            // photo llega como lista de tamaños: el último es el grande.
            $descriptor = isset($message['photo'])
                ? end($message['photo'])
                : $message['document'];

            if (is_array($descriptor) && ! empty($descriptor['file_id'])) {
                $media = [
                    'file_id' => (string) $descriptor['file_id'],
                    'mime' => $descriptor['mime_type'] ?? (isset($message['photo']) ? 'image/jpeg' : null),
                    'filename' => $descriptor['file_name'] ?? null,
                ];
                $body ??= isset($message['photo']) ? '[Imagen]' : '[Documento]';
            }
        }

        if ($body === null) {
            $body = '[Mensaje no soportado todavía]';
        }

        return [
            'from' => $chatId,
            'name' => $name ?: null,
            'body' => (string) $body,
            'externalId' => isset($message['message_id']) ? $chatId.':'.$message['message_id'] : null,
            'media' => $media,
        ];
    }

    /**
     * Mismo camino que Meta/Evolution, dentro del tenant dueño del bot.
     *
     * @param  array{file_id: string, mime: string|null, filename: string|null}|null  $media
     */
    protected function handleInbound(TelegramChannelLink $link, string $from, ?string $name, string $body, ?string $externalId, ?array $media = null): void
    {
        $tenant = Tenant::find($link->tenant_id);

        if (! $tenant || $from === '') {
            return;
        }

        $tenant->run(function () use ($link, $from, $name, $body, $externalId, $media) {
            // Telegram reintenta webhooks no confirmados: no duplicar.
            if ($externalId && Message::query()->where('meta->external_id', $externalId)->exists()) {
                return;
            }

            // Un Channel por bot conectado (external_id = link central):
            // cada bot tiene su propio modo auto/copilot/off en la bandeja.
            $channel = Channel::firstOrCreate(
                [
                    'property_id' => Property::firstOrFail()->id,
                    'type' => Channel::TYPE_TELEGRAM,
                    'external_id' => (string) $link->id,
                ],
                ['name' => $link->name ?: ($link->bot_username ? "Telegram @{$link->bot_username}" : 'Telegram'), 'mode' => 'auto', 'active' => true],
            );

            $conversation = Conversation::firstOrCreate(
                ['channel_id' => $channel->id, 'contact_phone' => $from],
                // bot_enabled EXPLÍCITO: el default de la DB no se hidrata en
                // el modelo recién creado (null = el bot calla al 1er mensaje).
                ['contact_name' => $name, 'status' => Conversation::STATUS_OPEN, 'bot_enabled' => true, 'last_message_at' => now()],
            );

            if ($name && ! $conversation->contact_name) {
                $conversation->update(['contact_name' => $name]);
            }
            if ($conversation->status === Conversation::STATUS_RESOLVED) {
                $conversation->update(['status' => Conversation::STATUS_OPEN]);
            }

            // El chat_id de Telegram NO es un teléfono: no ligar reservas
            // por coincidencia numérica accidental (mismo criterio que PSID).

            $message = $conversation->messages()->create([
                'direction' => 'in',
                'sender_type' => 'visitor',
                'body' => $body,
                'meta' => array_filter(['external_id' => $externalId, 'channel' => Channel::TYPE_TELEGRAM]),
                'created_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now()]);

            // Foto/documento: bajar el binario por la Bot API y dejar que el
            // servicio decida su destino (adjunto/comprobante).
            $mediaOutcome = null;

            if ($media !== null) {
                $binary = $this->api->downloadFile($link, $media['file_id']);

                if ($binary) {
                    $mediaOutcome = app(\App\Services\Channels\InboundMediaService::class)->handle(
                        $conversation,
                        $message,
                        $binary['contents'],
                        $media['mime'] ?? $binary['mime'],
                        $media['filename'] ?? null,
                    );
                }
            }

            // Comprobante detectado: el acuse ya salió y el staff lo verá
            // en /pagos — ni bot ni cola de "espera humano".
            if ($mediaOutcome === \App\Services\Channels\InboundMediaService::OUTCOME_RECEIPT) {
                return;
            }

            $brain = app(AgentBrain::class);

            // El bot no ve imágenes: una foto sin texto espera a un humano
            // (el servicio ya dejó la conversación en pendiente).
            $noCaption = $media !== null && in_array($body, ['[Imagen]', '[Documento]'], true);

            if (! $noCaption && $channel->mode === 'auto' && $conversation->bot_enabled && $brain->isConfigured()) {
                $reply = $brain->reply($conversation);

                if ($reply?->body) {
                    $this->api->sendText($link, $from, $reply->body);
                }
            } else {
                if ($conversation->status !== Conversation::STATUS_PENDING) {
                    $conversation->update(['status' => Conversation::STATUS_PENDING]);
                }

                // Observabilidad: por qué el bot NO intentó responder.
                \Illuminate\Support\Facades\Log::info('Bot: mensaje entrante sin respuesta automática', [
                    'conversation_id' => $conversation->id,
                    'channel_mode' => $channel->mode,
                    'bot_enabled' => $conversation->bot_enabled,
                    'llm_configured' => $brain->isConfigured(),
                    'blocked_reason' => $brain->gateStatus()['blocked_reason'] ?? null,
                ]);
            }
        });
    }
}
