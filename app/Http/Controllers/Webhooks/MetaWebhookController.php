<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSocialComment;
use App\Models\Central\MetaChannelLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialPost;
use App\Models\Tenant;
use App\Services\Agent\AgentBrain;
use App\Services\Channels\InboundVoiceService;
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
    /**
     * Tipo que manda Meta => clase de contenido nuestra. Junta los nombres
     * de WhatsApp (document, voice, sticker) con los de Messenger e
     * Instagram (file), que son los mismos adjuntos con otro nombre.
     */
    public const MEDIA_KINDS = [
        'image' => 'image',
        'document' => 'file',
        'file' => 'file',
        'audio' => InboundVoiceService::KIND_AUDIO,
        'voice' => InboundVoiceService::KIND_AUDIO,
        'video' => InboundVoiceService::KIND_VIDEO,
        'sticker' => InboundVoiceService::KIND_STICKER,
    ];

    /** Contenido que el bot no lee tal cual: se transcribe o se pide texto. */
    public const SPOKEN_KINDS = [
        InboundVoiceService::KIND_AUDIO,
        InboundVoiceService::KIND_VIDEO,
        InboundVoiceService::KIND_STICKER,
    ];

    public function __construct(protected MetaApi $api) {}

    /**
     * Etiqueta del mensaje cuando no viene texto. Imagen y documento
     * conservan la suya de siempre: el flujo de comprobantes se apoya en
     * esas dos cadenas exactas.
     */
    public static function placeholderFor(?string $kind, string $type = ''): string
    {
        return InboundVoiceService::label($kind, $type);
    }

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
                        // Imagen y documento se guardan como adjunto; nota
                        // de voz, video y sticker entran por el camino de
                        // InboundVoiceService (transcribir o pedir texto).
                        // Todos bajan su binario por la Graph API.
                        $type = (string) ($message['type'] ?? 'text');

                        // Una reacción (👍) no es un mensaje: contestarla con
                        // "¿algo más?" es ruido (caso cabañas 2026-09-13, conv. 635).
                        if ($type === 'reaction') {
                            continue;
                        }

                        $kind = self::MEDIA_KINDS[$type] ?? null;
                        $mediaInfo = $kind !== null ? ($message[$type] ?? null) : null;

                        $this->handleInbound(
                            $link,
                            from: (string) $message['from'],
                            name: $contactName,
                            body: $message['text']['body']
                                ?? $mediaInfo['caption']
                                ?? self::placeholderFor($mediaInfo !== null ? $kind : null, $type),
                            externalId: $message['id'] ?? null,
                            media: $mediaInfo !== null ? [
                                'kind' => $kind,
                                'media_id' => (string) ($mediaInfo['id'] ?? ''),
                                'mime' => $mediaInfo['mime_type'] ?? null,
                                'filename' => $mediaInfo['filename'] ?? null,
                                // La Cloud API manda la duración del audio;
                                // sirve para no transcribir monólogos.
                                'seconds' => isset($mediaInfo['duration']) ? (int) $mediaInfo['duration'] : null,
                            ] : null,
                        );
                    }

                    // Estatus de entrega: la Cloud API acepta el envío con
                    // 200 y reporta el fracaso DESPUÉS por aquí (p. ej.
                    // #131047 fuera de la ventana de 24 h). Sin esto, un
                    // mensaje que "salió bien" muere sin dejar rastro —
                    // tres rondas de pruebas en vivo lo sufrieron
                    // (2026-07-24, motellacupula).
                    foreach ($value['statuses'] ?? [] as $status) {
                        if (($status['status'] ?? '') === 'failed') {
                            Log::warning('Meta: entrega fallida (status asíncrono)', [
                                'tenant' => $link->tenant_id,
                                'to' => $status['recipient_id'] ?? null,
                                'message_id' => $status['id'] ?? null,
                                'errors' => $status['errors'] ?? [],
                            ]);
                        }
                    }
                }
            }

            // ── Comentarios en publicaciones (módulo redes-sociales) ──
            // field=feed en páginas de Facebook, field=comments en Instagram.
            // A diferencia de los DMs, esto NO se procesa aquí: un post con
            // pauta trae ráfagas de comentarios y N llamadas al LLM dentro
            // del webhook lo tumbarían por timeout (y Meta reintentaría,
            // duplicando respuestas públicas). Se encola y se contesta fuera.
            foreach ($entry['changes'] ?? [] as $change) {
                $comment = self::commentChangeToPayload($entry, $change);

                if (! $comment) {
                    continue;
                }

                $link = $this->link(
                    $comment['network'] === SocialPost::NETWORK_FACEBOOK ? ['messenger'] : ['instagram'],
                    $entry['id'] ?? null,
                );

                if ($link) {
                    // Rastro de llegada: la primera pregunta ante "no
                    // aparece el comentario" es si Meta siquiera nos llamó.
                    Log::info('Redes: comentario recibido', [
                        'tenant' => $link->tenant_id,
                        'red' => $comment['network'],
                        'comment_id' => $comment['comment_id'],
                        'verbo' => $comment['verb'],
                    ]);

                    ProcessSocialComment::dispatch($link->tenant_id, $link->id, $comment);
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
                            media: $normalized['media'] ? $normalized['media'] + ['media_id' => ''] : null,
                        );
                    }
                }
            }

            // ── Messenger / Instagram DM (array "messaging") ──
            foreach ($entry['messaging'] ?? [] as $event) {
                $text = $event['message']['text'] ?? null;
                $sender = (string) ($event['sender']['id'] ?? '');
                $media = self::firstDownloadableAttachment($event['message']['attachments'] ?? []);

                if ((! $text && ! $media) || ! empty($event['message']['is_echo']) || $sender === (string) ($entry['id'] ?? '')) {
                    continue; // ecos de lo que nosotros enviamos, u otros eventos
                }

                $link = $this->link(['messenger', 'instagram'], $entry['id'] ?? null);

                if ($link) {
                    $this->handleInbound(
                        $link,
                        from: $sender,
                        name: null,
                        body: $text ?: self::placeholderFor($media['kind'] ?? null),
                        externalId: $event['message']['mid'] ?? null,
                        media: $media ? $media + ['media_id' => ''] : null,
                    );
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Normaliza un "change" de Instagram Login a mensaje entrante. Devuelve
     * null para ecos (la cuenta hablando) y eventos sin texto NI adjunto
     * descargable (imagen o documento: el comprobante de transferencia
     * llega así — antes se tiraba en silencio y el huésped se quedaba sin
     * acuse, caso real cabañas 2026-08-28).
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $change
     * @return array{from: string, body: string, external_id: ?string, media: array{url: string, kind: string}|null}|null
     */
    public static function instagramChangeToMessage(array $entry, array $change): ?array
    {
        if (($change['field'] ?? '') !== 'messages') {
            return null;
        }

        $value = $change['value'] ?? [];
        $sender = (string) ($value['sender']['id'] ?? '');
        $text = $value['message']['text'] ?? null;
        $media = self::firstDownloadableAttachment($value['message']['attachments'] ?? []);

        if ($sender === '' || (! $text && ! $media)) {
            return null;
        }

        if (! empty($value['message']['is_echo']) || $sender === (string) ($entry['id'] ?? '')) {
            return null;
        }

        return [
            'from' => $sender,
            'body' => (string) ($text ?: self::placeholderFor($media['kind'] ?? null)),
            'external_id' => $value['message']['mid'] ?? null,
            'media' => $media,
        ];
    }

    /**
     * Primer adjunto descargable de un mensaje de Messenger/Instagram, con
     * URL directa del CDN de Meta. Imagen y documento se guardan como
     * adjunto; la nota de voz se transcribe y el video queda en aviso de
     * "solo texto" — antes los dos últimos se tiraban en silencio y el
     * huésped se quedaba sin respuesta (caso cabañas 2026-09-04).
     *
     * @param  array<int, mixed>  $attachments
     * @return array{url: string, kind: string}|null
     */
    public static function firstDownloadableAttachment(array $attachments): ?array
    {
        $spoken = null;

        foreach ($attachments as $attachment) {
            $type = (string) ($attachment['type'] ?? '');
            $url = (string) ($attachment['payload']['url'] ?? '');
            $kind = self::MEDIA_KINDS[$type] ?? null;

            if ($url === '' || $kind === null) {
                continue;
            }

            // Imagen y documento mandan sobre lo demás: si el huésped
            // acompaña su video con el comprobante, el que importa es el
            // comprobante. Lo hablado se guarda por si no viene ninguno.
            if (! in_array($kind, self::SPOKEN_KINDS, true)) {
                return ['url' => $url, 'kind' => $kind];
            }

            $spoken ??= ['url' => $url, 'kind' => $kind];
        }

        return $spoken;
    }

    /**
     * Normaliza un comentario de publicación a una forma común. Las dos redes
     * mandan formas distintas:
     *
     * - Facebook (field=feed): value.item=comment, verb=add|edited|remove,
     *   comment_id, post_id, from.{id,name}, message, created_time (epoch).
     * - Instagram (field=comments): value.id, text, from.{id,username},
     *   media.id — sin verb (IG no avisa ediciones ni borrados).
     *
     * Devuelve null para lo que no es un comentario y para los ecos de la
     * propia página (nuestras respuestas vuelven como evento).
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $change
     * @return array{network: string, verb: string, comment_id: string, parent_id: ?string, post_id: ?string, post_permalink: ?string, author_id: ?string, author_name: ?string, body: ?string, commented_at: ?string}|null
     */
    public static function commentChangeToPayload(array $entry, array $change): ?array
    {
        $field = (string) ($change['field'] ?? '');
        $value = $change['value'] ?? [];
        $accountId = (string) ($entry['id'] ?? '');

        if ($field === 'feed') {
            if (($value['item'] ?? '') !== 'comment') {
                return null; // likes, reacciones, posts nuevos: no es lo nuestro
            }

            $commentId = (string) ($value['comment_id'] ?? '');
            $authorId = (string) ($value['from']['id'] ?? '');

            if ($commentId === '' || ($authorId !== '' && $authorId === $accountId)) {
                return null;
            }

            $created = $value['created_time'] ?? null;

            return [
                'network' => SocialPost::NETWORK_FACEBOOK,
                'verb' => (string) ($value['verb'] ?? 'add'),
                'comment_id' => $commentId,
                'parent_id' => isset($value['parent_id']) ? (string) $value['parent_id'] : null,
                'post_id' => isset($value['post_id']) ? (string) $value['post_id'] : null,
                'post_permalink' => $value['post']['permalink_url'] ?? null,
                'author_id' => $authorId ?: null,
                'author_name' => $value['from']['name'] ?? null,
                'body' => $value['message'] ?? null,
                'commented_at' => is_numeric($created)
                    ? now()->setTimestamp((int) $created)->toDateTimeString()
                    : null,
            ];
        }

        if ($field === 'comments') {
            $commentId = (string) ($value['id'] ?? '');
            $authorId = (string) ($value['from']['id'] ?? '');

            if ($commentId === '' || ($authorId !== '' && $authorId === $accountId)) {
                return null;
            }

            return [
                'network' => SocialPost::NETWORK_INSTAGRAM,
                'verb' => 'add', // Instagram solo notifica altas
                'comment_id' => $commentId,
                'parent_id' => isset($value['parent_id']) ? (string) $value['parent_id'] : null,
                'post_id' => isset($value['media']['id']) ? (string) $value['media']['id'] : null,
                'post_permalink' => null,
                'author_id' => $authorId ?: null,
                'author_name' => $value['from']['username'] ?? null,
                'body' => $value['text'] ?? null,
                'commented_at' => null,
            ];
        }

        return null;
    }

    /**
     * El mismo camino que el webchat, dentro del tenant dueño del canal:
     * conversación por contacto + mensaje + bot (o cola para humano).
     *
     * @param  array{media_id: string, mime: string|null, filename: string|null}|null  $media
     */
    protected function handleInbound(MetaChannelLink $link, string $from, ?string $name, string $body, ?string $externalId, ?array $media = null): void
    {
        $tenant = Tenant::find($link->tenant_id);

        if (! $tenant || $from === '') {
            return;
        }

        $tenant->run(function () use ($link, $from, $name, $body, $externalId, $media) {
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

            // Solo WhatsApp trae teléfono real (Messenger/IG mandan un PSID
            // numérico que podría coincidir por accidente con un teléfono).
            if ($link->type === 'whatsapp') {
                $conversation->linkReservationByPhone();
            }

            // Nota de voz, video o sticker: se resuelve ANTES de guardar,
            // para que en la bandeja se lea el dictado y no un marcador.
            $voice = app(InboundVoiceService::class);
            $kind = $media['kind'] ?? null;
            // Con pie de foto manda el texto: un video con caption es un
            // mensaje escrito con adjunto, no un mensaje que no se puede leer.
            $spoken = in_array($kind, self::SPOKEN_KINDS, true)
                && $body === InboundVoiceService::label((string) $kind);
            $transcribed = false;
            $spokenMeta = [];

            if ($spoken) {
                $interpreted = $voice->interpret(
                    (string) $kind,
                    fn () => $link->type === 'whatsapp'
                        ? $this->api->downloadMedia($link, (string) ($media['media_id'] ?? ''))
                        : $this->api->downloadMediaUrl((string) ($media['url'] ?? '')),
                    $media['seconds'] ?? null,
                );

                $body = $interpreted['body'];
                $spokenMeta = $interpreted['meta'];
                $transcribed = $interpreted['transcribed'];
            }

            $message = $conversation->messages()->create([
                'direction' => 'in',
                'sender_type' => 'visitor',
                'body' => $body,
                'meta' => array_filter(['external_id' => $externalId, 'channel' => $link->type] + $spokenMeta),
                'created_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now()]);

            // Imagen/documento de WhatsApp: bajar por la Graph API y dejar
            // que el servicio decida su destino (adjunto/comprobante).
            $mediaOutcome = null;

            if (! $spoken && $media !== null && ($media['media_id'] ?? '') !== '' && $link->type === 'whatsapp') {
                $binary = $this->api->downloadMedia($link, $media['media_id']);

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

            // Messenger/Instagram mandan el adjunto como URL directa del CDN
            // de Meta: mismo destino (adjunto del mensaje o comprobante).
            if (! $spoken && $media !== null && ($media['url'] ?? '') !== '' && in_array($link->type, ['messenger', 'instagram'], true)) {
                $binary = $this->api->downloadMediaUrl($media['url']);

                if ($binary) {
                    $mediaOutcome = app(\App\Services\Channels\InboundMediaService::class)->handle(
                        $conversation,
                        $message,
                        $binary['contents'],
                        $binary['mime'],
                    );
                }
            }

            // Comprobante detectado: el acuse ya salió y el staff lo verá
            // en /pagos — ni bot ni cola de "espera humano".
            if ($mediaOutcome === \App\Services\Channels\InboundMediaService::OUTCOME_RECEIPT) {
                return;
            }

            // Llegó algo que no se pudo leer: se avisa que por aquí solo
            // texto y a espera humana. El huésped SÍ contestó — dejarlo en
            // silencio es lo que hacía que el seguimiento le preguntara
            // "¿sigues por ahí?" a quien acababa de hablar.
            if ($spoken && ! $transcribed) {
                $voice->askForText($conversation, (string) $kind);

                return;
            }

            $brain = app(AgentBrain::class);

            // El bot no ve imágenes: una foto sin texto espera a un humano
            // (el servicio ya dejó la conversación en pendiente).
            // Salvo que se haya leído y NO sea comprobante: el bot ya sabe
            // qué se ve y puede contestar.
            $noCaption = $media !== null && in_array($body, ['[Imagen]', '[Documento]'], true)
                && $mediaOutcome !== \App\Services\Channels\InboundMediaService::OUTCOME_DESCRIBED;

            if (! $noCaption && $channel->mode === 'auto' && $conversation->bot_enabled && $brain->isConfigured()) {
                // "escribiendo..." antes de pensar: la respuesta tarda de 3
                // a 8 segundos y ese hueco se lee como que nadie contestó.
                $this->api->sendTyping($link, $from, $externalId);

                $reply = $brain->replyTo($conversation, $message);

                // Si el canal la rechaza, el huésped no la recibe aunque la
                // bandeja la muestre: que alguien del hotel lo sepa.
                if ($reply?->body && ! $this->api->sendText($link, $from, $reply->body)) {
                    app(\App\Services\Channels\OutboundMessenger::class)->flagUndelivered($conversation, $reply);
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
        // Apps por hotel: cada tenant con app propia firma con SU clave.
        // El webhook es uno solo, así que se aceptan todas las registradas
        // (más las de la plataforma como respaldo).
        $tenantSecrets = \App\Models\Central\TenantMetaApp::query()->get()
            ->flatMap(fn ($app) => [(string) $app->app_secret, (string) $app->ig_app_secret])
            ->all();

        $secrets = array_unique(array_filter([
            (string) config('meta.app_secret'),
            (string) config('meta.ig_app_secret'),
            ...$tenantSecrets,
        ]));

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
