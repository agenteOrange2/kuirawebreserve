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
        // `comments` alimenta el módulo de redes sociales; suscribirlo no
        // estorba a quien no lo tenga (los eventos se descartan sin módulo).
        if ($this->usesInstagramLogin($link)) {
            try {
                return Http::withToken($link->access_token)->timeout(10)
                    ->post($this->igGraph().'/me/subscribed_apps', [
                        'subscribed_fields' => 'messages,comments',
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
                        // feed = comentarios en publicaciones de la página.
                        'subscribed_fields' => 'messages,messaging_postbacks,feed',
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

    /*
    |--------------------------------------------------------------------------
    | Comentarios en publicaciones (módulo redes-sociales)
    |--------------------------------------------------------------------------
    | Requieren permisos DISTINTOS a los de mensajería: pages_manage_engagement
    | (Facebook) e instagram_manage_comments (Instagram). Con la app sin esos
    | permisos aprobados, Graph responde 200 con un error de permisos en el
    | cuerpo — por eso cada método registra el cuerpo exacto: es la diferencia
    | entre diagnosticar en minutos o a ciegas.
    */

    /**
     * Responde EN PÚBLICO al hilo del comentario. Devuelve el id de la
     * respuesta creada (para no repetirla) o null si falló.
     */
    public function replyToComment(MetaChannelLink $link, string $commentId, string $message): ?string
    {
        // Cada red nombra distinto el mismo hilo: en Facebook la respuesta a
        // un comentario es otro comentario suyo (/comments), en Instagram es
        // /replies. Usar el edge equivocado devuelve "Object does not exist"
        // aunque el comentario esté ahí (comprobado en vivo, 2026-08-20).
        $edge = $link->type === 'instagram' ? 'replies' : 'comments';

        try {
            $response = Http::withToken($link->access_token)->timeout(10)
                ->post($this->commentGraph($link)."/{$commentId}/{$edge}", ['message' => $message]);

            if ($response->failed() || $response->json('error')) {
                $this->logCommentFailure('respuesta pública', $link, $commentId, $response);

                return null;
            }

            return $response->json('id');
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Mensaje PRIVADO al autor del comentario (private reply). Meta permite
     * uno solo por comentario y dentro de los 7 días.
     *
     * Se manda por el Send API con recipient.comment_id a propósito: su
     * respuesta trae `recipient_id`, que es el PSID/IGSID con el que la
     * bandeja arma la conversación. Sin ese id no habría forma de ligar el
     * comentario con el DM cuando la persona conteste.
     *
     * @return array{message_id: ?string, recipient_id: ?string}|null
     */
    public function privateReply(MetaChannelLink $link, string $commentId, string $message): ?array
    {
        $endpoint = ($this->usesInstagramLogin($link) ? $this->igGraph() : rtrim(config('meta.graph_url'), '/')).'/me/messages';

        try {
            $response = Http::withToken($link->access_token)->timeout(10)
                ->post($endpoint, [
                    'recipient' => ['comment_id' => $commentId],
                    'message' => ['text' => $message],
                ]);

            if ($response->failed() || $response->json('error')) {
                $this->logCommentFailure('mensaje privado', $link, $commentId, $response);

                return null;
            }

            return [
                'message_id' => $response->json('message_id'),
                'recipient_id' => $response->json('recipient_id'),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Oculta o vuelve a mostrar un comentario. Ocultar NUNCA borra: el
     * comentario sigue visible para su autor y la acción es reversible.
     * Facebook usa is_hidden; Instagram usa hide.
     */
    public function hideComment(MetaChannelLink $link, string $commentId, bool $hidden = true): bool
    {
        $isInstagram = $link->type === 'instagram';

        try {
            $response = Http::withToken($link->access_token)->timeout(10)
                ->post($this->commentGraph($link)."/{$commentId}", $isInstagram
                    ? ['hide' => $hidden ? 'true' : 'false']
                    : ['is_hidden' => $hidden ? 'true' : 'false']);

            if ($response->failed() || $response->json('error')) {
                $this->logCommentFailure($hidden ? 'ocultar' : 'mostrar', $link, $commentId, $response);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Detalle de una publicación (texto, liga e imagen) para poder mostrarla
     * en el panel junto a sus comentarios.
     *
     * @return array<string, mixed>|null
     */
    public function postDetail(MetaChannelLink $link, string $postId): ?array
    {
        $isInstagram = $link->type === 'instagram';

        try {
            $response = Http::withToken($link->access_token)->timeout(10)
                ->get($this->commentGraph($link)."/{$postId}", [
                    'fields' => $isInstagram
                        ? 'id,caption,permalink,media_url,thumbnail_url,timestamp,comments_count'
                        : 'id,message,permalink_url,created_time,full_picture',
                ]);

            if ($response->failed() || $response->json('error')) {
                $this->logCommentFailure('detalle de publicación', $link, $postId, $response);

                return null;
            }

            return $this->normalizePost($response->json(), $isInstagram);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Publicaciones de la página o cuenta desde una fecha, ya normalizadas.
     *
     * Sigue la paginación de Graph (que devuelve de 25 en 25 aunque se pida
     * más) hasta agotar el periodo o topar el máximo: sin esto, "escanear el
     * último año" traía solo la primera página y parecía que no había nada.
     *
     * Instagram no acepta `since`/`until` en /media: ahí se pagina y se corta
     * en cuanto aparece una publicación más vieja que el periodo pedido.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accountPosts(MetaChannelLink $link, ?\DateTimeInterface $since = null, int $max = 200): array
    {
        $isInstagram = $link->type === 'instagram';
        $accountId = $this->accountId($link);

        if (! $accountId) {
            return [];
        }

        $url = $this->commentGraph($link)."/{$accountId}/".($isInstagram ? 'media' : 'posts');
        $query = [
            'fields' => $isInstagram
                ? 'id,caption,permalink,media_url,thumbnail_url,timestamp,comments_count'
                : 'id,message,permalink_url,created_time,full_picture,comments.summary(true)',
            'limit' => 50,
        ];

        if ($since && ! $isInstagram) {
            $query['since'] = $since->getTimestamp();
        }

        $posts = [];
        // Un cursor que se repite (Graph a veces devuelve el mismo `next`)
        // haría girar el bucle hasta el tope: se corta al primer repetido.
        $visited = [];

        try {
            while (count($posts) < $max) {
                if (isset($visited[$url])) {
                    break;
                }

                $visited[$url] = true;

                $response = Http::withToken($link->access_token)->timeout(20)->get($url, $query);

                if ($response->failed() || $response->json('error')) {
                    $this->logCommentFailure('lista de publicaciones', $link, (string) $accountId, $response);

                    break;
                }

                $page = $response->json('data') ?? [];

                if ($page === []) {
                    break;
                }

                foreach ($page as $post) {
                    $normalized = $this->normalizePost($post, $isInstagram);

                    // Instagram no filtra por fecha en el servidor: se corta aquí.
                    if ($since && $normalized['published_at']
                        && strtotime((string) $normalized['published_at']) < $since->getTimestamp()) {
                        return $posts;
                    }

                    $posts[] = $normalized;
                }

                $next = $response->json('paging.next');

                if (! $next) {
                    break;
                }

                // El cursor `next` ya trae todos los parámetros firmados.
                $url = $next;
                $query = [];
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Facebook NO devuelve por /posts todo lo que la página publicó: una
        // foto o un video suben como historia propia y ahí se comentan. Se
        // complementan por sus edges y se ligan al post real con
        // `page_story_id`, que es el id con el que se leen sus comentarios.
        if (! $isInstagram) {
            $posts = $this->mergeMediaStories($link, $posts, $since);
        }

        return $posts;
    }

    /**
     * Agrega fotos y videos de la página que no vinieron por /posts.
     *
     * @param  array<int, array<string, mixed>>  $posts
     * @return array<int, array<string, mixed>>
     */
    protected function mergeMediaStories(MetaChannelLink $link, array $posts, ?\DateTimeInterface $since): array
    {
        $graph = rtrim(config('meta.graph_url'), '/');
        $known = array_flip(array_column($posts, 'external_id'));

        foreach (['photos' => 'name', 'videos' => 'description'] as $edge => $textField) {
            try {
                $response = Http::withToken($link->access_token)->timeout(20)
                    ->get("{$graph}/{$link->external_id}/{$edge}", [
                        'fields' => "id,created_time,page_story_id,permalink_url,{$textField},"
                            .($edge === 'photos' ? 'images,' : 'picture,')
                            .'comments.summary(true)',
                        'limit' => 50,
                    ]);

                if ($response->failed() || $response->json('error')) {
                    // Sin permiso para este edge no se rompe el escaneo: la
                    // página puede no tener fotos o videos y da igual.
                    continue;
                }

                foreach ($response->json('data') ?? [] as $item) {
                    // Sin page_story_id no hay hilo de comentarios al cual
                    // responder: no sirve para este módulo.
                    $storyId = (string) ($item['page_story_id'] ?? '');

                    if ($storyId === '' || isset($known[$storyId])) {
                        continue;
                    }

                    $created = $item['created_time'] ?? null;

                    if ($since && $created && strtotime((string) $created) < $since->getTimestamp()) {
                        continue;
                    }

                    $known[$storyId] = true;
                    $posts[] = [
                        'external_id' => $storyId,
                        'message' => $item[$textField] ?? null,
                        'permalink' => $item['permalink_url'] ?? null,
                        'media_url' => $item['images'][0]['source'] ?? $item['picture'] ?? null,
                        'published_at' => $created ? (string) $created : null,
                        'comments_count' => (int) ($item['comments']['summary']['total_count'] ?? 0),
                    ];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Más recientes primero, como las entrega /posts.
        usort($posts, fn (array $a, array $b) => strtotime((string) ($b['published_at'] ?? '1970-01-01'))
            <=> strtotime((string) ($a['published_at'] ?? '1970-01-01')));

        return $posts;
    }

    /**
     * Permisos que el módulo de redes necesita y el token NO trae.
     *
     * Caso real (motellacupula, 2026-08-20): el token tenía pages_messaging
     * — por eso el bot contestaba DMs sin problema — pero le faltaban los de
     * publicaciones, así que Graph devolvía la página vacía SIN error. Un
     * fallo mudo que costó varias rondas de diagnóstico manual.
     *
     * Devuelve [] cuando no se puede determinar: nunca inventar una alarma.
     *
     * @return array<string, string> permiso => para qué sirve
     */
    public function missingCommentPermissions(MetaChannelLink $link): array
    {
        // Instagram Login no expone scopes por debug_token de la app de
        // Facebook: ahí no se puede afirmar nada.
        if ($link->type !== 'messenger') {
            return [];
        }

        $needed = [
            'pages_read_engagement' => 'leer las publicaciones y sus comentarios',
            'pages_manage_engagement' => 'responder y ocultar comentarios',
        ];

        $appId = (string) config('meta.app_id');
        $appSecret = (string) config('meta.app_secret');

        if ($appId === '' || $appSecret === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)->get(rtrim(config('meta.graph_url'), '/').'/debug_token', [
                'input_token' => $link->access_token,
                'access_token' => "{$appId}|{$appSecret}",
            ]);

            $scopes = $response->json('data.scopes');

            if (! is_array($scopes)) {
                return [];
            }

            return array_diff_key($needed, array_flip($scopes));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Convierte un token de USUARIO en el token de PÁGINA correspondiente,
     * de larga duración.
     *
     * Por qué existe: activar un permiso en la app NO actualiza los tokens
     * ya emitidos — un token es una foto de los permisos que había cuando se
     * generó. Quien conecta tiene que generar uno NUEVO después de conceder
     * el permiso, y además cambiar el desplegable del Explorador a "token de
     * página". Ese paso se equivoca casi siempre (motellacupula, 2026-08-20:
     * tres rondas atorado ahí), así que aquí se acepta el token de usuario
     * tal cual y el canje lo hace el sistema:
     *
     *   token de usuario corto → largo (nunca caduca para la página) → token
     *   de la página elegida, que hereda sus permisos.
     *
     * Devuelve null si ya era token de página o si no se pudo canjear: quien
     * llama se queda con lo que pegó.
     */
    public function pageTokenFrom(string $token, string $pageId): ?string
    {
        $graph = rtrim(config('meta.graph_url'), '/');
        $appId = (string) config('meta.app_id');
        $appSecret = (string) config('meta.app_secret');

        if ($appId === '' || $appSecret === '' || $pageId === '') {
            return null;
        }

        // Los tokens de Instagram Login (IGAA…) no son de esta familia.
        if (str_starts_with($token, 'IG')) {
            return null;
        }

        try {
            $type = Http::timeout(10)->get("{$graph}/debug_token", [
                'input_token' => $token,
                'access_token' => "{$appId}|{$appSecret}",
            ])->json('data.type');

            if ($type !== 'USER') {
                return null; // ya es de página (o no se pudo saber): no tocar
            }

            // Larga duración: sin esto el token de página hereda las ~2 horas
            // del token corto del Explorador y la conexión muere sola.
            $long = Http::timeout(10)->get("{$graph}/oauth/access_token", [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $token,
            ])->json('access_token') ?? $token;

            $accounts = Http::withToken($long)->timeout(10)
                ->get("{$graph}/me/accounts", ['fields' => 'id,name,access_token', 'limit' => 100]);

            foreach ($accounts->json('data') ?? [] as $page) {
                if ((string) ($page['id'] ?? '') === $pageId) {
                    return $page['access_token'] ?? null;
                }
            }

            Log::warning('Meta: el token de usuario no administra esa página', [
                'page_id' => $pageId,
                'paginas' => array_column($accounts->json('data') ?? [], 'id'),
            ]);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Todo lo que le falta a la conexión para que los comentarios funcionen.
     *
     * Son TRES capas independientes, y cualquiera de las tres rompe el
     * módulo en silencio (caso real motellacupula, 2026-08-20 — las tres
     * fallaban a la vez y ninguna avisaba):
     *
     *  1. Permisos del token: sin pages_read_engagement, Graph devuelve el
     *     muro VACÍO sin error.
     *  2. Campos del webhook en la app de Meta: sin `feed` (o `comments` en
     *     Instagram), Meta nunca manda el evento de un comentario nuevo.
     *  3. Campos suscritos de la página: los repara resubscribe().
     *
     * @return array<int, array{tipo: string, detalle: string, accion: string}>
     */
    public function commentSetupIssues(MetaChannelLink $link): array
    {
        $issues = [];
        $isInstagram = $link->type === 'instagram';

        if ($missing = $this->missingCommentPermissions($link)) {
            $issues[] = [
                'tipo' => 'Permisos del acceso guardado',
                'detalle' => implode(', ', array_keys($missing)),
                // El malentendido clásico: activar el permiso en la app no
                // cambia un acceso ya guardado (es una foto de los permisos
                // que había al generarlo). Hay que generar otro y pegarlo.
                'accion' => 'Activar el permiso en la app NO actualiza el acceso que ya está guardado aquí: hay que generar uno nuevo DESPUÉS de conceder el permiso y pegarlo en la conexión. Puedes pegar el token de usuario del Explorador de la API tal cual: el sistema obtiene solo el de la página.',
            ];
        }

        // Campo del webhook que trae los comentarios en cada red.
        $field = $isInstagram ? 'comments' : 'feed';

        if ($this->appWebhookMissing($isInstagram ? 'instagram' : 'page', $field)) {
            $issues[] = [
                'tipo' => 'Webhook de la app',
                'detalle' => $field,
                'accion' => 'En el panel de la app de Meta, en Webhooks → '
                    .($isInstagram ? 'Instagram' : 'Página')
                    .", pulsa Suscribirte en el campo \"{$field}\". Sin esto Meta nunca avisa de un comentario nuevo.",
            ];
        }

        if (! $isInstagram && ($fields = $this->pageSubscribedFields($link)) !== null
            && ! in_array('feed', $fields, true)) {
            $issues[] = [
                'tipo' => 'Suscripción de la página',
                'detalle' => 'feed',
                'accion' => 'Usa el botón Reparar suscripción del asistente: lo deja listo sin salir del sistema.',
            ];
        }

        return $issues;
    }

    /**
     * ¿La app de Meta tiene sin suscribir este campo de webhook?
     * Devuelve false ante la duda: nunca inventar una alarma.
     */
    protected function appWebhookMissing(string $object, string $field): bool
    {
        $appId = (string) config('meta.app_id');
        $appSecret = (string) config('meta.app_secret');

        if ($appId === '' || $appSecret === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)->get(rtrim(config('meta.graph_url'), '/')."/{$appId}/subscriptions", [
                'access_token' => "{$appId}|{$appSecret}",
            ]);

            $data = $response->json('data');

            if (! is_array($data)) {
                return false;
            }

            foreach ($data as $subscription) {
                if (($subscription['object'] ?? '') !== $object) {
                    continue;
                }

                $fields = array_map(
                    fn ($item) => is_array($item) ? ($item['name'] ?? '') : $item,
                    $subscription['fields'] ?? [],
                );

                return ! in_array($field, $fields, true);
            }

            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Campos con los que la página está suscrita a la app, o null si no se
     * pudo consultar.
     *
     * @return array<int, string>|null
     */
    public function pageSubscribedFields(MetaChannelLink $link): ?array
    {
        $pageId = $link->type === 'messenger' ? $link->external_id : $link->waba_id;

        if (! $pageId) {
            return null;
        }

        try {
            $response = Http::withToken($link->access_token)->timeout(10)
                ->get(rtrim(config('meta.graph_url'), '/')."/{$pageId}/subscribed_apps");

            $data = $response->json('data');

            if (! is_array($data) || $data === []) {
                return null;
            }

            return array_values(array_unique(array_merge(
                ...array_map(fn (array $app) => $app['subscribed_fields'] ?? [], $data),
            )));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Nombre de la cuenta conectada (y si su token sigue vivo). Se usa para
     * decirle al hotel QUÉ cuenta se escaneó: conectar la página equivocada
     * es un error mudo, y así deja de serlo.
     *
     * @return array{name: ?string, ok: bool, error: ?string}
     */
    public function accountIdentity(MetaChannelLink $link): array
    {
        $isInstagram = $link->type === 'instagram';

        try {
            $response = Http::withToken($link->access_token)->timeout(10)
                ->get($this->commentGraph($link).'/'.($this->usesInstagramLogin($link) ? 'me' : $link->external_id), [
                    'fields' => $isInstagram ? 'username,name' : 'name',
                ]);

            if ($response->failed() || $response->json('error')) {
                return [
                    'name' => $link->name,
                    'ok' => false,
                    'error' => $response->json('error.message') ?? 'no se pudo leer la cuenta',
                ];
            }

            return [
                'name' => $response->json('name') ?? $response->json('username') ?? $link->name,
                'ok' => true,
                'error' => null,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['name' => $link->name, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Comentarios de una publicación, normalizados a la misma forma que
     * entrega el webhook (para reutilizar el mismo camino de guardado).
     *
     * @return array<int, array<string, mixed>>
     */
    public function postComments(MetaChannelLink $link, string $postId, int $limit = 50): array
    {
        $isInstagram = $link->type === 'instagram';

        try {
            $response = Http::withToken($link->access_token)->timeout(15)
                ->get($this->commentGraph($link)."/{$postId}/comments", [
                    'fields' => $isInstagram
                        ? 'id,text,username,from,timestamp,parent_id'
                        : 'id,message,from,created_time,parent,is_hidden',
                    'limit' => $limit,
                ]);

            if ($response->failed() || $response->json('error')) {
                $this->logCommentFailure('lista de comentarios', $link, $postId, $response);

                return [];
            }

            return array_map(function (array $comment) use ($isInstagram) {
                $date = $isInstagram ? ($comment['timestamp'] ?? null) : ($comment['created_time'] ?? null);

                return [
                    'comment_id' => (string) ($comment['id'] ?? ''),
                    'parent_id' => isset($comment['parent']['id'])
                        ? (string) $comment['parent']['id']
                        : (isset($comment['parent_id']) ? (string) $comment['parent_id'] : null),
                    'author_id' => isset($comment['from']['id']) ? (string) $comment['from']['id'] : null,
                    'author_name' => $comment['from']['name'] ?? $comment['username'] ?? null,
                    'body' => $comment['message'] ?? $comment['text'] ?? null,
                    'commented_at' => $date ? (string) $date : null,
                    'hidden' => (bool) ($comment['is_hidden'] ?? false),
                ];
            }, $response->json('data') ?? []);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Cuenta dueña de las publicaciones: la página en Messenger, y en
     * Instagram la propia cuenta (Login) o la del id externo.
     */
    public function accountId(MetaChannelLink $link): ?string
    {
        return match ($link->type) {
            'messenger' => $link->external_id,
            'instagram' => $this->usesInstagramLogin($link) ? 'me' : $link->external_id,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    protected function normalizePost(array $post, bool $isInstagram): array
    {
        $date = $isInstagram ? ($post['timestamp'] ?? null) : ($post['created_time'] ?? null);

        return [
            'external_id' => (string) ($post['id'] ?? ''),
            'message' => $post['caption'] ?? $post['message'] ?? null,
            'permalink' => $post['permalink'] ?? $post['permalink_url'] ?? null,
            'media_url' => $post['media_url'] ?? $post['thumbnail_url'] ?? $post['full_picture'] ?? null,
            'published_at' => $date ? (string) $date : null,
            // Instagram lo da plano; Facebook dentro del summary del edge.
            'comments_count' => (int) ($post['comments_count']
                ?? $post['comments']['summary']['total_count']
                ?? 0),
        ];
    }

    /** Host de Graph para operaciones de comentarios según la ruta del canal. */
    protected function commentGraph(MetaChannelLink $link): string
    {
        return $this->usesInstagramLogin($link)
            ? $this->igGraph()
            : rtrim(config('meta.graph_url'), '/');
    }

    protected function logCommentFailure(string $action, MetaChannelLink $link, string $target, mixed $response): void
    {
        Log::warning("Meta: {$action} falló", [
            'type' => $link->type,
            'tenant' => $link->tenant_id,
            'target' => $target,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);
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
