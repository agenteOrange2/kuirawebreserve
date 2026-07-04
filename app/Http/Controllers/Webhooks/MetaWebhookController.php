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

        foreach ($payload['entry'] ?? [] as $entry) {
            // ── WhatsApp Cloud API (field "messages") ──
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

            // ── Messenger / Instagram DM (array "messaging") ──
            foreach ($entry['messaging'] ?? [] as $event) {
                $text = $event['message']['text'] ?? null;

                if (! $text || ! empty($event['message']['is_echo'])) {
                    continue; // ecos de lo que nosotros enviamos, u otros eventos
                }

                $link = $this->link(['messenger', 'instagram'], $entry['id'] ?? null);

                if ($link) {
                    $this->handleInbound(
                        $link,
                        from: (string) ($event['sender']['id'] ?? ''),
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
                ['contact_name' => $name, 'status' => Conversation::STATUS_OPEN, 'last_message_at' => now()],
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
            } elseif ($conversation->status !== Conversation::STATUS_PENDING) {
                $conversation->update(['status' => Conversation::STATUS_PENDING]);
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
        }

        return $link;
    }

    /**
     * Firma X-Hub-Signature-256 con el app secret. En entorno de prueba
     * (sin META_APP_SECRET) se omite para poder usar túneles y curl.
     */
    protected function validSignature(Request $request): bool
    {
        $secret = (string) config('meta.app_secret');

        if ($secret === '') {
            return config('meta.mode') !== 'production';
        }

        $signature = (string) $request->header('X-Hub-Signature-256');

        return $signature !== ''
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
