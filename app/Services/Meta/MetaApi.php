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
    /**
     * Descarga un medio entrante de WhatsApp Cloud API en dos pasos:
     * GET /{media_id} da la URL firmada (vive ~5 min) y esa URL se baja
     * con el mismo token en el header — sin token, el CDN regresa 404.
     *
     * @return array{contents: string, mime: string}|null
     */
    public function downloadMedia(MetaChannelLink $link, string $mediaId): ?array
    {
        $graph = rtrim(config('meta.graph_url'), '/');

        try {
            $meta = Http::withToken($link->access_token)->get("{$graph}/{$mediaId}");

            if ($meta->failed() || ! $meta->json('url')) {
                Log::warning('Meta: descarga de media fallida (lookup)', [
                    'tenant' => $link->tenant_id,
                    'media_id' => $mediaId,
                    'status' => $meta->status(),
                    'body' => $meta->json(),
                ]);

                return null;
            }

            $binary = Http::withToken($link->access_token)->get((string) $meta->json('url'));

            if ($binary->failed() || $binary->body() === '') {
                Log::warning('Meta: descarga de media fallida (binario)', [
                    'tenant' => $link->tenant_id,
                    'media_id' => $mediaId,
                    'status' => $binary->status(),
                ]);

                return null;
            }

            return [
                'contents' => $binary->body(),
                'mime' => (string) ($meta->json('mime_type') ?? $binary->header('Content-Type') ?? 'application/octet-stream'),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function sendText(MetaChannelLink $link, string $to, string $text): bool
    {
        $graph = rtrim(config('meta.graph_url'), '/');

        // México: el wa_id entrante trae el "1" heredado (52 1 + 10 dígitos),
        // pero la Cloud API espera 52 + 10; con número de prueba el 521 ni
        // siquiera pasa la lista de destinatarios autorizados (#131030).
        if ($link->type === 'whatsapp' && str_starts_with($to, '521') && strlen($to) === 13) {
            $to = '52'.substr($to, 3);
        }

        try {
            $response = match (true) {
                // WhatsApp Cloud API: POST /{phone_number_id}/messages
                $link->type === 'whatsapp' => Http::withToken($link->access_token)
                    ->post("{$graph}/{$link->external_id}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to' => $to,
                        'type' => 'text',
                        'text' => ['body' => $text],
                    ]),
                // Instagram vía "Instagram Login" (token IGAA…): otro host.
                $this->usesInstagramLogin($link) => Http::withToken($link->access_token)
                    ->post($this->igGraph().'/me/messages', [
                        'recipient' => ['id' => $to],
                        'message' => ['text' => $text],
                    ]),
                // Messenger / Instagram vía página: Send API con token de página
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
     * Radiografía del canal: token vivo, identidad y suscripción de la app.
     * WhatsApp revisa número/calidad/callback/WABA; Messenger e Instagram
     * revisan la PÁGINA (nombre + subscribed_apps) — la causa #1 de "el
     * webhook está verificado pero no llegan mensajes" es la página sin
     * suscribir a la app, igual que con la WABA.
     *
     * @return array<string, mixed>
     */
    public function diagnose(MetaChannelLink $link): array
    {
        if ($link->type !== 'whatsapp') {
            return $this->diagnosePage($link);
        }

        $graph = rtrim(config('meta.graph_url'), '/');
        $result = [
            'token_ok' => false,
            'phone' => null,
            'quality' => null,
            'callback_url' => null,
            'callback_ok' => null,
            'subscribed' => null,
            'last_event_at' => $link->last_event_at?->diffForHumans(),
        ];

        try {
            $number = Http::withToken($link->access_token)->timeout(10)
                ->get("{$graph}/{$link->external_id}", [
                    'fields' => 'display_phone_number,verified_name,quality_rating,webhook_configuration',
                ]);

            if ($number->successful()) {
                $result['token_ok'] = true;
                $result['phone'] = trim(($number->json('display_phone_number') ?? '').' · '.($number->json('verified_name') ?? ''), ' ·');
                $result['quality'] = $number->json('quality_rating');
                $result['callback_url'] = $number->json('webhook_configuration.application');
                $result['callback_ok'] = $result['callback_url'] === route('webhooks.meta');
            }

            if ($link->waba_id) {
                $subs = Http::withToken($link->access_token)->timeout(10)
                    ->get("{$graph}/{$link->waba_id}/subscribed_apps");

                if ($subs->successful()) {
                    // La suscripción de la PROPIA app del token no revela su
                    // nombre aquí; el indicador fiable es que el ciclo de
                    // re-suscripción (botón Reparar) devuelva success.
                    $result['subscribed'] = count($subs->json('data') ?? []) > 0
                        ? array_map(fn ($a) => $a['whatsapp_business_api_data']['name'] ?? '?', $subs->json('data'))
                        : [];
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }

    /**
     * Radiografía de canales de página (Messenger / Instagram DM). Para
     * Instagram el external_id es la cuenta profesional; la suscripción
     * vive en la PÁGINA de Facebook vinculada, cuyo id se captura en el
     * campo waba_id del vínculo (columna genérica "cuenta padre").
     *
     * @return array<string, mixed>
     */
    protected function diagnosePage(MetaChannelLink $link): array
    {
        // Instagram Login: no hay página; la identidad y la suscripción de
        // la CUENTA se validan contra graph.instagram.com.
        if ($this->usesInstagramLogin($link)) {
            $result = [
                'token_ok' => false,
                'identity' => null,
                'page_id' => null,
                'subscribed' => null,
                'subscribed_fields' => null,
                'last_event_at' => $link->last_event_at?->diffForHumans(),
            ];

            try {
                $me = Http::withToken($link->access_token)->timeout(10)
                    ->get($this->igGraph().'/me', ['fields' => 'username,name']);

                if ($me->successful()) {
                    $result['token_ok'] = true;
                    $result['identity'] = trim(($me->json('name') ?? '').' · '.($me->json('username') ?? ''), ' ·');
                }

                $subs = Http::withToken($link->access_token)->timeout(10)
                    ->get($this->igGraph().'/me/subscribed_apps');

                if ($subs->successful()) {
                    $apps = $subs->json('data') ?? [];
                    $result['subscribed'] = array_map(fn ($a) => $a['name'] ?? 'App '.($a['id'] ?? '?'), $apps);
                    $result['subscribed_fields'] = collect($apps)
                        ->flatMap(fn ($a) => $a['subscribed_fields'] ?? [])
                        ->unique()
                        ->values()
                        ->all();
                }
            } catch (Throwable $e) {
                report($e);
            }

            return $result;
        }

        $graph = rtrim(config('meta.graph_url'), '/');
        $pageId = $link->type === 'messenger' ? $link->external_id : $link->waba_id;

        $result = [
            'token_ok' => false,
            'identity' => null,
            'page_id' => $pageId,
            'subscribed' => null,
            'subscribed_fields' => null,
            'last_event_at' => $link->last_event_at?->diffForHumans(),
        ];

        try {
            // Identidad: página (Messenger) o cuenta profesional (Instagram).
            $identity = Http::withToken($link->access_token)->timeout(10)
                ->get("{$graph}/{$link->external_id}", [
                    'fields' => $link->type === 'instagram' ? 'username,name' : 'name',
                ]);

            if ($identity->successful()) {
                $result['token_ok'] = true;
                $result['identity'] = trim(
                    ($identity->json('name') ?? '').' · '.($identity->json('username') ?? ''),
                    ' ·',
                );
            }

            if ($pageId) {
                $subs = Http::withToken($link->access_token)->timeout(10)
                    ->get("{$graph}/{$pageId}/subscribed_apps");

                if ($subs->successful()) {
                    $apps = $subs->json('data') ?? [];
                    $result['subscribed'] = array_map(fn ($a) => $a['name'] ?? '?', $apps);
                    $result['subscribed_fields'] = collect($apps)
                        ->flatMap(fn ($a) => $a['subscribed_fields'] ?? [])
                        ->unique()
                        ->values()
                        ->all();
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }

    /**
     * Repara la suscripción de la app: WABA (baja + alta) para WhatsApp, o
     * página con el campo messages para Messenger/Instagram. Arregla el
     * caso clásico: URL verificada y campo suscrito en la app, pero la
     * cuenta/página nunca quedó suscrita a la app del token.
     */
    public function resubscribe(MetaChannelLink $link): bool
    {
        $graph = rtrim(config('meta.graph_url'), '/');

        // Instagram Login: la CUENTA se suscribe a la app por su propia API.
        if ($this->usesInstagramLogin($link)) {
            try {
                return Http::withToken($link->access_token)->timeout(10)
                    ->post($this->igGraph().'/me/subscribed_apps', [
                        'subscribed_fields' => 'messages',
                    ])
                    ->successful();
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        }

        if ($link->type !== 'whatsapp') {
            $pageId = $link->type === 'messenger' ? $link->external_id : $link->waba_id;

            if (! $pageId) {
                return false;
            }

            try {
                return Http::withToken($link->access_token)->timeout(10)
                    ->post("{$graph}/{$pageId}/subscribed_apps", [
                        'subscribed_fields' => 'messages,messaging_postbacks',
                    ])
                    ->successful();
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        }

        if (! $link->waba_id) {
            return false;
        }

        try {
            Http::withToken($link->access_token)->timeout(10)
                ->delete("{$graph}/{$link->waba_id}/subscribed_apps");

            return Http::withToken($link->access_token)->timeout(10)
                ->post("{$graph}/{$link->waba_id}/subscribed_apps")
                ->successful();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Nombre visible del contacto (best-effort): Messenger expone name del
     * PSID; Instagram username/name de la cuenta. Sin esto la bandeja
     * muestra "Visitante" en todos los DMs. Nunca lanza.
     */
    public function contactName(MetaChannelLink $link, string $contactId): ?string
    {
        if (! in_array($link->type, ['messenger', 'instagram'], true)) {
            return null;
        }

        $graph = $this->usesInstagramLogin($link)
            ? $this->igGraph()
            : rtrim(config('meta.graph_url'), '/');

        try {
            $response = Http::withToken($link->access_token)->timeout(5)
                ->get("{$graph}/{$contactId}", [
                    'fields' => $link->type === 'instagram' ? 'username,name' : 'name',
                ]);

            if (! $response->successful()) {
                return null;
            }

            return $response->json('name') ?? $response->json('username');
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Ruta "API con inicio de sesión de Instagram": tokens propios (IGAA…)
     * que hablan con graph.instagram.com. La ruta clásica vía página usa
     * tokens de página (EAA…) contra graph.facebook.com.
     */
    protected function usesInstagramLogin(MetaChannelLink $link): bool
    {
        return $link->type === 'instagram'
            && str_starts_with((string) $link->access_token, 'IG');
    }

    protected function igGraph(): string
    {
        return rtrim(config('meta.ig_graph_url'), '/');
    }

    /**
     * Envía a la persona detrás de una conversación de canal Meta (el id
     * externo del contacto vive en contact_phone). Para webchat u otros
     * canales no hace nada: el visitante lee por polling.
     */
    public function pushToConversation(Conversation $conversation, string $text): bool
    {
        $link = $this->linkForConversation($conversation);

        return $link ? $this->sendText($link, $conversation->contact_phone, $text) : false;
    }

    /**
     * Adjunto saliente por WhatsApp Cloud API: primero se sube el archivo a
     * /{phone_number_id}/media y luego se manda por su id.
     *
     * Se hace en dos pasos a propósito, aunque la API acepte una URL: los
     * adjuntos de una conversación se sirven tras autenticación y exponerlos
     * en una URL pública para que Meta los baje sería filtrarlos.
     *
     * Messenger sube el archivo en la misma llamada de envío (multipart).
     *
     * Instagram NO: su Send API solo acepta adjuntos por URL pública, y los
     * de una conversación se sirven tras autenticación — publicarlos para
     * que Meta los baje sería filtrarlos. Devuelve false y el staff ve el
     * aviso en vez de creer que llegó.
     */
    public function sendMedia(
        MetaChannelLink $link,
        string $to,
        string $path,
        string $mime,
        string $fileName,
        ?string $caption = null,
    ): bool {
        if ($link->type === 'messenger') {
            return $this->sendMessengerMedia($link, $to, $path, $mime, $fileName);
        }

        if ($link->type !== 'whatsapp') {
            return false;
        }

        $graph = rtrim(config('meta.graph_url'), '/');
        $to = $this->normalizeWhatsappNumber($link, $to);

        try {
            $upload = Http::withToken($link->access_token)
                ->attach('file', file_get_contents($path), $fileName, ['Content-Type' => $mime])
                ->post("{$graph}/{$link->external_id}/media", [
                    'messaging_product' => 'whatsapp',
                    'type' => $mime,
                ]);

            $mediaId = $upload->json('id');

            if ($upload->failed() || ! $mediaId) {
                Log::warning('Meta: subida de adjunto fallida', [
                    'tenant' => $link->tenant_id,
                    'status' => $upload->status(),
                    'body' => $upload->json(),
                ]);

                return false;
            }

            $isImage = str_starts_with($mime, 'image/');
            $payload = array_filter([
                'id' => $mediaId,
                'caption' => $caption,
                'filename' => $isImage ? null : $fileName,
            ], fn ($value) => $value !== null && $value !== '');

            $response = Http::withToken($link->access_token)
                ->post("{$graph}/{$link->external_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => $isImage ? 'image' : 'document',
                    $isImage ? 'image' : 'document' => $payload,
                ]);

            if ($response->failed()) {
                Log::warning('Meta: adjunto no enviado', [
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
     * Messenger: el archivo viaja en la MISMA llamada de envío, multipart,
     * sin paso previo de subida ni URL pública de por medio.
     */
    protected function sendMessengerMedia(
        MetaChannelLink $link,
        string $to,
        string $path,
        string $mime,
        string $fileName,
    ): bool {
        $graph = rtrim(config('meta.graph_url'), '/');

        try {
            $response = Http::withToken($link->access_token)
                ->attach('filedata', file_get_contents($path), $fileName, ['Content-Type' => $mime])
                ->post("{$graph}/me/messages", [
                    'recipient' => json_encode(['id' => $to]),
                    'messaging_type' => 'RESPONSE',
                    'message' => json_encode([
                        'attachment' => [
                            'type' => str_starts_with($mime, 'image/') ? 'image' : 'file',
                            'payload' => ['is_reusable' => false],
                        ],
                    ]),
                ]);

            if ($response->failed()) {
                Log::warning('Meta: adjunto de Messenger no enviado', [
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

    public function pushMediaToConversation(
        Conversation $conversation,
        string $path,
        string $mime,
        string $fileName,
        ?string $caption = null,
    ): bool {
        $link = $this->linkForConversation($conversation);

        return $link
            ? $this->sendMedia($link, $conversation->contact_phone, $path, $mime, $fileName, $caption)
            : false;
    }

    /** Canal conectado del tenant para esta conversación, si lo hay. */
    protected function linkForConversation(Conversation $conversation): ?MetaChannelLink
    {
        $type = $conversation->channel?->type;

        if (! in_array($type, MetaChannelLink::TYPES, true) || ! tenant() || ! $conversation->contact_phone) {
            return null;
        }

        return MetaChannelLink::query()
            ->where('tenant_id', tenant('id'))
            ->where('type', $type)
            ->where('active', true)
            ->first();
    }

    /**
     * México: el wa_id entrante trae el "1" heredado (52 1 + 10 dígitos)
     * pero la Cloud API espera 52 + 10.
     */
    protected function normalizeWhatsappNumber(MetaChannelLink $link, string $to): string
    {
        if ($link->type === 'whatsapp' && str_starts_with($to, '521') && strlen($to) === 13) {
            return '52'.substr($to, 3);
        }

        return $to;
    }
}
