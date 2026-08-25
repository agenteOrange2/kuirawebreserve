<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Central\TiktokChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use App\Services\Tiktok\TiktokApi;
use Illuminate\Http\Request;

/**
 * Receptor de webhooks de mensajería de TikTok (dominio central, sin
 * sesión/CSRF). La URL con webhook_token se pega en el panel de la app de
 * TikTok; el token identifica el vínculo (y con él al tenant). A partir de
 * ahí el camino es el mismo que Meta/Evolution/Telegram: conversación +
 * mensaje + bot IA si el canal está en automático.
 */
class TiktokWebhookController extends Controller
{
    public function __construct(protected TiktokApi $api) {}

    public function receive(Request $request, string $token)
    {
        $link = TiktokChannelLink::query()
            ->where('webhook_token', $token)
            ->where('active', true)
            ->first();

        if (! $link) {
            return response()->json(['ok' => false], 404);
        }

        // Verificación de la URL: TikTok manda un challenge al registrarla
        // y espera que se le devuelva tal cual.
        $challenge = $request->input('challenge') ?? $request->query('challenge');

        if ($challenge !== null) {
            return response()->json(['challenge' => $challenge]);
        }

        // Latido del canal (cualquier evento cuenta como señal de vida).
        $link->forceFill(['last_event_at' => now()])->saveQuietly();

        $payload = $request->json()->all();

        // Comentarios de videos: TikTok todavía no abre esta API a
        // aplicaciones sin aprobar, así que por ahora solo se registran para
        // que el panel los muestre. No se responden solos a propósito: sin
        // API de respuesta, prometerlo sería mentira.
        if ($this->looksLikeComment($payload)) {
            $this->storeComment($link, $payload);

            return response()->json(['ok' => true]);
        }

        foreach (self::extractMessages($payload) as $inbound) {
            $this->handleInbound($link, ...$inbound);
        }

        return response()->json(['ok' => true]);
    }

    /** @param array<string, mixed> $payload */
    protected function looksLikeComment(array $payload): bool
    {
        $event = strtolower((string) ($payload['event'] ?? $payload['type'] ?? ''));

        return $event !== '' && str_contains($event, 'comment');
    }

    /**
     * Guarda el comentario en el mismo modelo que Facebook e Instagram: si
     * TikTok abre la API, solo hay que enchufar el responder.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function storeComment(TiktokChannelLink $link, array $payload): void
    {
        $tenant = Tenant::find($link->tenant_id);

        if (! $tenant || ! $tenant->hasModule('redes-sociales')) {
            return;
        }

        $data = $payload['data'] ?? $payload['content'] ?? $payload;
        $commentId = (string) ($data['comment_id'] ?? $data['id'] ?? '');

        if ($commentId === '') {
            return;
        }

        $tenant->run(function () use ($data, $commentId, $link) {
            $post = \App\Models\SocialPost::firstOrCreate(
                [
                    'network' => \App\Models\SocialPost::NETWORK_TIKTOK,
                    'external_id' => (string) ($data['video_id'] ?? $data['item_id'] ?? 'desconocida-tiktok'),
                ],
                ['account_external_id' => $link->business_id],
            );

            \App\Models\SocialComment::firstOrCreate(
                ['external_id' => $commentId],
                [
                    'social_post_id' => $post->id,
                    'author_external_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
                    'author_name' => $data['username'] ?? $data['nickname'] ?? null,
                    'body' => $data['text'] ?? $data['content'] ?? null,
                    'commented_at' => now(),
                    'status' => \App\Models\SocialComment::STATUS_NEW,
                ],
            );
        });
    }

    /**
     * Normaliza el payload del webhook de mensajes de TikTok a mensajes
     * entrantes. TikTok ha cambiado el sobre entre versiones (evento suelto
     * en `data` o lote en `messages`), así que se aceptan ambos; los ecos
     * de la propia cuenta quedan fuera.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{from: string, name: string|null, body: string, externalId: string|null}>
     */
    public static function extractMessages(array $payload): array
    {
        $event = strtolower((string) ($payload['event'] ?? $payload['type'] ?? ''));

        if ($event !== '' && ! str_contains($event, 'message')) {
            return [];
        }

        $data = $payload['data'] ?? $payload['content'] ?? [];
        $items = array_is_list($data) ? $data : ($data === [] ? [] : [$data]);

        $messages = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $from = (string) ($item['sender_id'] ?? $item['from_user_id'] ?? $item['sender']['id'] ?? '');
            $businessId = (string) ($item['business_id'] ?? $item['recipient_id'] ?? '');

            if ($from === '' || $from === $businessId) {
                continue; // eco de lo que la cuenta envió
            }

            $body = $item['message']['text']
                ?? $item['text']
                ?? $item['content']['text']
                ?? null;

            $messages[] = [
                'from' => $from,
                'name' => $item['sender']['nickname'] ?? $item['sender_name'] ?? null,
                'body' => (string) ($body ?? '['.($item['message']['type'] ?? $item['message_type'] ?? 'mensaje').' no soportado todavía]'),
                'externalId' => $item['message_id'] ?? $item['message']['id'] ?? null,
            ];
        }

        return $messages;
    }

    /** Mismo camino que Meta/Evolution/Telegram, dentro del tenant dueño. */
    protected function handleInbound(TiktokChannelLink $link, string $from, ?string $name, string $body, ?string $externalId): void
    {
        $tenant = Tenant::find($link->tenant_id);

        if (! $tenant || $from === '') {
            return;
        }

        $tenant->run(function () use ($link, $from, $name, $body, $externalId) {
            // TikTok reintenta webhooks no confirmados: no duplicar.
            if ($externalId && Message::query()->where('meta->external_id', $externalId)->exists()) {
                return;
            }

            // Un Channel por cuenta conectada (external_id = link central).
            $channel = Channel::firstOrCreate(
                [
                    'property_id' => Property::firstOrFail()->id,
                    'type' => Channel::TYPE_TIKTOK,
                    'external_id' => (string) $link->id,
                ],
                ['name' => $link->name ?: 'TikTok', 'mode' => 'auto', 'active' => true],
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

            // El open_id de TikTok NO es un teléfono: no ligar reservas por
            // coincidencia numérica accidental (mismo criterio que PSID).

            $conversation->messages()->create([
                'direction' => 'in',
                'sender_type' => 'visitor',
                'body' => $body,
                'meta' => array_filter(['external_id' => $externalId, 'channel' => Channel::TYPE_TIKTOK]),
                'created_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now()]);

            $brain = app(AgentBrain::class);

            if ($channel->mode === 'auto' && $conversation->bot_enabled && $brain->isConfigured()) {
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
