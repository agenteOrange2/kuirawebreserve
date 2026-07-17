<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Central\EvolutionChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use App\Services\Evolution\EvolutionApi;
use Illuminate\Http\Request;

/**
 * Receptor de webhooks de Evolution API (dominio central, sin sesión/CSRF).
 * Cada instancia apunta a /webhooks/evolution/{token}; el token identifica
 * el vínculo (y con él al tenant), así que no hace falta firma adicional.
 * A partir de ahí el camino es el mismo que Meta/webchat: conversación +
 * mensaje + bot si el canal está en automático.
 */
class EvolutionWebhookController extends Controller
{
    public function __construct(protected EvolutionApi $api) {}

    public function receive(Request $request, string $token)
    {
        $link = EvolutionChannelLink::query()
            ->where('webhook_token', $token)
            ->where('active', true)
            ->first();

        if (! $link) {
            return response()->json(['ok' => false], 404);
        }

        // Latido del canal (cualquier evento cuenta como señal de vida).
        $link->forceFill(['last_event_at' => now()])->saveQuietly();

        foreach (self::extractMessages($request->json()->all()) as $inbound) {
            $this->handleInbound($link, ...$inbound);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Normaliza el payload de Evolution (v1/v2, evento suelto o lote) a
     * mensajes entrantes de texto. Ignora ecos propios (fromMe), grupos y
     * estados de difusión.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{from: string, name: string|null, body: string, externalId: string|null}>
     */
    public static function extractMessages(array $payload): array
    {
        $event = strtolower(str_replace('_', '.', (string) ($payload['event'] ?? '')));

        if ($event !== '' && $event !== 'messages.upsert') {
            return [];
        }

        $data = $payload['data'] ?? [];
        // v2 manda un solo mensaje en data; v1 puede mandar lote.
        $items = isset($data['key']) ? [$data] : (array_is_list($data) ? $data : []);

        $messages = [];

        foreach ($items as $item) {
            $key = $item['key'] ?? [];
            $jid = (string) ($key['remoteJid'] ?? '');

            if ($jid === '' || ! empty($key['fromMe'])) {
                continue; // eco de lo que nosotros enviamos
            }
            if (str_contains($jid, '@g.us') || str_starts_with($jid, 'status@')) {
                continue; // grupos y estados: fuera del flujo de reservas
            }

            $content = $item['message'] ?? [];
            $body = $content['conversation']
                ?? $content['extendedTextMessage']['text']
                ?? $content['imageMessage']['caption']
                ?? null;

            $messages[] = [
                'from' => strstr($jid, '@', true) ?: $jid,
                'name' => $item['pushName'] ?? null,
                'body' => $body ?? '['.($item['messageType'] ?? 'mensaje').' no soportado todavía]',
                'externalId' => $key['id'] ?? null,
            ];
        }

        return $messages;
    }

    /**
     * Mismo camino que Meta/webchat, dentro del tenant dueño de la instancia.
     */
    protected function handleInbound(EvolutionChannelLink $link, string $from, ?string $name, string $body, ?string $externalId): void
    {
        $tenant = Tenant::find($link->tenant_id);

        if (! $tenant || $from === '') {
            return;
        }

        $tenant->run(function () use ($link, $from, $name, $body, $externalId) {
            // Evolution puede reintentar webhooks: no duplicar mensajes.
            if ($externalId && Message::query()->where('meta->external_id', $externalId)->exists()) {
                return;
            }

            // Un Channel por instancia conectada (external_id = link central):
            // cada número tiene su propio modo auto/copilot/off en la bandeja.
            $channel = Channel::firstOrCreate(
                [
                    'property_id' => Property::firstOrFail()->id,
                    'type' => Channel::TYPE_WHATSAPP_EVOLUTION,
                    'external_id' => (string) $link->id,
                ],
                ['name' => $link->name ?: "WhatsApp {$link->instance}", 'mode' => 'auto', 'active' => true],
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

            $conversation->messages()->create([
                'direction' => 'in',
                'sender_type' => 'visitor',
                'body' => $body,
                'meta' => array_filter(['external_id' => $externalId, 'channel' => Channel::TYPE_WHATSAPP_EVOLUTION]),
                'created_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now()]);

            $brain = app(AgentBrain::class);

            if ($channel->mode === 'auto' && $conversation->bot_enabled && $brain->isConfigured()) {
                $reply = $brain->reply($conversation);

                if ($reply?->body) {
                    // Con "escribiendo..." y retraso humano (anti-ban): el
                    // reintento del webhook no duplica gracias al dedupe.
                    $this->api->sendText($link, $from, $reply->body, EvolutionApi::humanDelay($reply->body));
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
