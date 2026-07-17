<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use App\Services\Meta\MetaApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receptor ÚNICO de webhooks de Meta (dominio central, sin sesión/CSRF).
 * Enruta cada evento al tenant dueño del número/página (meta_channel_links)
 * y ahí reutiliza el mismo camino que el webchat: conversación + mensaje +
 * bot si el canal está en automático. Responde 200 siempre que se pueda
 * (Meta reintenta los fallidos).
 */
class MetaWebhookController extends Controller
{
    public function __construct(protected MetaApi $api) {}

    /** Verificación de la suscripción (GET con hub.challenge). */
    public function verify(Request $request)
    {
        if ($request->query('hub_mode') === 'subscribe'
            && hash_equals((string) config('meta.verify_token'), (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        return response('Forbidden', 403);
    }

    /** Eventos entrantes (POST). */
    public function receive(Request $request)
    {
        if (! $this->validSignature($request)) {
            return response('Invalid signature', 401);
        }

        $payload = $request->json()->all();
        $object = (string) ($payload['object'] ?? '');

        foreach ($payload['entry'] ?? [] as $entry) {
            // ── WhatsApp Cloud API (object whatsapp_business_account) ──
            // El guard por object importa: Instagram Login también manda
            // "changes" con field=messages y sin él se tragaba en silencio.
            if ($object === 'whatsapp_business_account' || $object === '') {
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? '') !== 'messages') {
                        continue;
                    }

                    $value = $change['value'] ?? [];
                    $link = $this->link('whatsapp', $value['metadata']['phone_number_id'] ?? null);

                    if (! $link) {
                        continue;
                    }

                    $contactName = $value['contacts'][0]['profile']['name'] ?? null;

                    foreach ($value['messages'] ?? [] as $message) {
                        $this->handleInbound(
                            $link,
                            from: (string) $message['from'],
                            name: $contactName,
                            body: $message['text']['body'] ?? '['.($message['type'] ?? 'mensaje').' no soportado todavía]',
                            externalId: $message['id'] ?? null,
                        );
                    }
                }
            }

            // ── Instagram Login: DMs en formato "changes" (field messages) ──
            if ($object === 'instagram') {
                foreach ($entry['changes'] ?? [] as $change) {
                    $normalized = self::instagramChangeToMessage($entry, $change);

                    if (! $normalized) {
                        continue;
                    }

                    $link = $this->link('instagram', $entry['id'] ?? null);

                    if ($link) {
                        $this->handleInbound(
                            $link,
                            from: $normalized['from'],
                            name: null,
                            body: $normalized['body'],
                            externalId: $normalized['external_id'],
                        );
                    }
                }
            }

            // ── Messenger / Instagram DM (array "messaging") ──
            foreach ($entry['messaging'] ?? [] as $event) {
                $text = $event['message']['text'] ?? null;
                $sender = (string) ($event['sender']['id'] ?? '');

                if (! $text || ! empty($event['message']['is_echo']) || $sender === (string) ($entry['id'] ?? '')) {
                    continue; // ecos de lo que nosotros enviamos, u otros eventos
                }

                $link = $this->link(['messenger', 'instagram'], $entry['id'] ?? null);

                if ($link) {
                    $this->handleInbound(
                        $link,
                        from: $sender,
                        name: null,
                        body: $text,
                        externalId: $event['message']['mid'] ?? null,
                    );
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Normaliza un "change" de Instagram Login a mensaje entrante. Devuelve
     * null para ecos (la cuenta hablando) y eventos que no son texto.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $change
     * @return array{from: string, body: string, external_id: ?string}|null
     */
    public static function instagramChangeToMessage(array $entry, array $change): ?array
    {
        if (($change['field'] ?? '') !== 'messages') {
            return null;
        }

        $value = $change['value'] ?? [];
        $sender = (string) ($value['sender']['id'] ?? '');
        $text = $value['message']['text'] ?? null;

        if ($sender === '' || ! $text) {
            return null;
        }

        if (! empty($value['message']['is_echo']) || $sender === (string) ($entry['id'] ?? '')) {
            return null;
        }

        return [
            'from' => $sender,
            'body' => (string) $text,
            'external_id' => $value['message']['mid'] ?? null,
        ];
    }

    /**
     * El mismo camino que el webchat, dentro del tenant dueño del canal:
     * conversación por contacto + mensaje + bot (o cola para humano).
     */
    protected function handleInbound(MetaChannelLink $link, string $from, ?string $name, string $body, ?string $externalId): void
    {
        $tenant = Tenant::find($link->tenant_id);

        if (! $tenant || $from === '') {
            return;
        }

        $tenant->run(function () use ($link, $from, $name, $body, $externalId) {
            // Meta reintenta webhooks: no duplicar mensajes ya procesados.
            if ($externalId && Message::query()->where('meta->external_id', $externalId)->exists()) {
                return;
            }

            $channel = Channel::firstOrCreate(
                ['property_id' => \App\Models\Property::firstOrFail()->id, 'type' => $link->type],
                ['name' => $link->typeLabel(), 'mode' => 'auto', 'active' => true],
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

            // Messenger/IG no traen el nombre en el evento: se consulta una
            // sola vez (al crear la conversación) para no mostrar "Visitante".
            if ($conversation->wasRecentlyCreated && ! $conversation->contact_name) {
                $fetched = $this->api->contactName($link, $from);

                if ($fetched) {
                    $conversation->update(['contact_name' => $fetched]);
                }
            }
            if ($conversation->status === Conversation::STATUS_RESOLVED) {
                $conversation->update(['status' => Conversation::STATUS_OPEN]);
            }

            $conversation->messages()->create([
                'direction' => 'in',
                'sender_type' => 'visitor',
                'body' => $body,
                'meta' => array_filter(['external_id' => $externalId, 'channel' => $link->type]),
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

                // Observabilidad: por qué el bot NO intentó responder (la
                // conversación cae a "espera humano" sin rastro si no).
                Log::info('Bot: mensaje entrante sin respuesta automática', [
                    'conversation_id' => $conversation->id,
                    'channel_mode' => $channel->mode,
                    'bot_enabled' => $conversation->bot_enabled,
                    'llm_configured' => $brain->isConfigured(),
                    'blocked_reason' => $brain->gateStatus()['blocked_reason'] ?? null,
                ]);
            }
        });
    }

    /** @param string|array<int, string> $types */
    protected function link(string|array $types, ?string $externalId): ?MetaChannelLink
    {
        if (! $externalId) {
            return null;
        }

        $link = MetaChannelLink::query()
            ->whereIn('type', (array) $types)
            ->where('external_id', $externalId)
            ->where('active', true)
            ->first();

        if (! $link) {
            Log::info('Meta: evento de canal no vinculado', ['external_id' => $externalId, 'types' => $types]);
        } else {
            // Latido del canal: el admin ve de un vistazo si llegan eventos.
            $link->forceFill(['last_event_at' => now()])->saveQuietly();
        }

        return $link;
    }

    /**
     * Firma X-Hub-Signature-256. Dos firmantes válidos: el app secret de
     * Facebook (WhatsApp/Messenger/IG vía página) y el de la app anidada de
     * Instagram Login (tokens IGAA…, firma propia). En entorno de prueba
     * (sin secretos) se omite para poder usar túneles y curl.
     */
    protected function validSignature(Request $request): bool
    {
        $secrets = array_filter([
            (string) config('meta.app_secret'),
            (string) config('meta.ig_app_secret'),
        ]);

        if ($secrets === []) {
            return config('meta.mode') !== 'production';
        }

        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($signature === '') {
            return false;
        }

        foreach ($secrets as $secret) {
            if (hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
                return true;
            }
        }

        return false;
    }
}
