<?php

use App\Models\Central\MetaChannelLink;
use App\Models\Property;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Services\Meta\MetaApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Property::factory()->create();

    config()->set('meta.graph_url', 'https://graph.test/v21.0');

    // El mismo tenant que ve el controlador (en pruebas, el de la conexión
    // por defecto): sin esto el canal no se encuentra y no se pide nada.
    $this->link = MetaChannelLink::create([
        'tenant_id' => (string) tenant('id'),
        'type' => 'messenger',
        'external_id' => 'PAGE123',
        'access_token' => 'token-abc',
        'active' => true,
    ]);
});

function fbPost(string $id, string $when, int $comments = 0): array
{
    return [
        'id' => $id,
        'message' => "Publicación {$id}",
        'permalink_url' => "https://facebook.com/{$id}",
        'created_time' => $when,
        'full_picture' => 'https://scontent.test/'.str_repeat('a', 600).'.jpg',
        'comments' => ['summary' => ['total_count' => $comments]],
    ];
}

it('el escaneo pide a Facebook solo el periodo elegido', function () {
    Http::fake(['graph.test/*' => Http::response(['data' => []])]);

    app(\App\Http\Controllers\Tenant\SocialSyncController::class)
        ->store(Request::create('/redes/sincronizar', 'POST', ['meses' => 6]));

    Http::assertSent(function ($request) {
        $since = (int) (explode('since=', $request->url())[1] ?? 0);

        return str_contains($request->url(), '/PAGE123/posts')
            && $since > 0
            // Seis meses atrás, con holgura de un día por el startOfDay.
            && abs($since - now()->subMonths(6)->getTimestamp()) < 86400 * 2;
    });
});

it('sigue la paginación de Graph: no se queda con la primera página', function () {
    // Graph pagina de 25 en 25 aunque se pida más: sin seguir el cursor,
    // "escanear el último año" traería solo la primera tanda.
    Http::fake([
        'graph.test/*' => Http::sequence()
            ->push([
                'data' => [fbPost('p1', now()->subDays(2)->toIso8601String())],
                'paging' => ['next' => 'https://graph.test/v21.0/PAGE123/posts?after=CURSOR'],
            ])
            ->push(['data' => [fbPost('p2', now()->subDays(5)->toIso8601String())]]),
    ]);

    $posts = app(MetaApi::class)->accountPosts($this->link, now()->subMonth());

    expect($posts)->toHaveCount(2)
        ->and($posts[1]['external_id'])->toBe('p2');
});

it('corta el paginado si Graph repite el mismo cursor', function () {
    // Un `next` que apunta a sí mismo giraría hasta el tope de 200.
    Http::fake([
        'graph.test/*' => Http::response([
            'data' => [fbPost('p1', now()->subDays(2)->toIso8601String())],
            'paging' => ['next' => 'https://graph.test/v21.0/PAGE123/posts?after=MISMO'],
        ]),
    ]);

    $posts = app(MetaApi::class)->accountPosts($this->link, now()->subMonth());

    expect(count($posts))->toBeLessThan(5);
});

it('suma las fotos publicadas que Facebook no devuelve por /posts', function () {
    // Caso real: la página publica una foto y esa historia NO aparece en
    // /posts, así que el panel se veía vacío aunque hubiera publicaciones.
    Http::fake([
        'graph.test/v21.0/PAGE123/posts*' => Http::response([
            'data' => [fbPost('PAGE123_p1', now()->subDays(2)->toIso8601String())],
        ]),
        'graph.test/v21.0/PAGE123/photos*' => Http::response([
            'data' => [
                [
                    'id' => 'foto1',
                    // Es el id del post real: con él se leen sus comentarios.
                    'page_story_id' => 'PAGE123_foto1',
                    'created_time' => now()->subDay()->toIso8601String(),
                    'name' => 'Promoción de fin de semana',
                    'images' => [['source' => 'https://scontent.test/foto1.jpg']],
                    'comments' => ['summary' => ['total_count' => 0]],
                ],
                // Sin page_story_id no hay hilo al cual responder: se ignora.
                ['id' => 'foto2', 'created_time' => now()->toIso8601String()],
                // Fuera del periodo pedido.
                [
                    'id' => 'foto3', 'page_story_id' => 'PAGE123_foto3',
                    'created_time' => now()->subYears(2)->toIso8601String(),
                ],
            ],
        ]),
        'graph.test/v21.0/PAGE123/videos*' => Http::response(['data' => []]),
    ]);

    $posts = app(MetaApi::class)->accountPosts($this->link, now()->subMonth());

    expect($posts)->toHaveCount(2)
        // La foto es más reciente que el post: manda el orden por fecha.
        ->and($posts[0]['external_id'])->toBe('PAGE123_foto1')
        ->and($posts[0]['message'])->toBe('Promoción de fin de semana')
        ->and($posts[1]['external_id'])->toBe('PAGE123_p1');
});

it('no duplica una foto que ya vino como publicación', function () {
    Http::fake([
        'graph.test/v21.0/PAGE123/posts*' => Http::response([
            'data' => [fbPost('PAGE123_p1', now()->subDays(2)->toIso8601String())],
        ]),
        'graph.test/v21.0/PAGE123/photos*' => Http::response([
            'data' => [[
                'id' => 'foto1',
                'page_story_id' => 'PAGE123_p1', // la misma historia
                'created_time' => now()->subDays(2)->toIso8601String(),
            ]],
        ]),
        'graph.test/v21.0/PAGE123/videos*' => Http::response(['data' => []]),
    ]);

    expect(app(MetaApi::class)->accountPosts($this->link, now()->subMonth()))->toHaveCount(1);
});

it('avisa qué permisos le faltan al acceso en vez de mostrar la página vacía', function () {
    // Caso real (motellacupula, 2026-08-20): el token tenía pages_messaging
    // pero no los de publicaciones, así que Graph devolvía vacío SIN error
    // y el panel parecía roto sin decir por qué.
    config()->set('meta.app_id', 'APP1');
    config()->set('meta.app_secret', 'SECRET1');

    Http::fake([
        'graph.test/v21.0/debug_token*' => Http::response(['data' => [
            'is_valid' => true,
            'scopes' => ['pages_messaging', 'pages_show_list'],
        ]]),
        'graph.test/v21.0/PAGE123?*' => Http::response(['name' => 'Pagina del Hotel']),
        '*' => Http::response(['data' => []]),
    ]);

    app(\App\Http\Controllers\Tenant\SocialSyncController::class)
        ->store(Request::create('/redes/sincronizar', 'POST', ['meses' => 1]));

    expect(session('error'))->toContain('pages_read_engagement')
        ->and(session('error'))->toContain('pages_manage_engagement')
        ->and(session('error'))->toContain('por eso no se ven las publicaciones');
});

it('detecta las tres capas que rompen los comentarios en silencio', function () {
    // Caso real motellacupula (2026-08-20): las tres fallaban a la vez y
    // ninguna avisaba — permisos, campo del webhook en la app, y campos
    // suscritos de la página.
    config()->set('meta.app_id', 'APP1');
    config()->set('meta.app_secret', 'SECRET1');

    Http::fake([
        'graph.test/v21.0/debug_token*' => Http::response(['data' => [
            'scopes' => ['pages_messaging'], // sin los de engagement
        ]]),
        'graph.test/v21.0/APP1/subscriptions*' => Http::response(['data' => [
            ['object' => 'page', 'fields' => [['name' => 'messages']]], // sin feed
        ]]),
        'graph.test/v21.0/PAGE123/subscribed_apps*' => Http::response(['data' => [
            ['subscribed_fields' => ['messages', 'messaging_postbacks']], // sin feed
        ]]),
        '*' => Http::response(['data' => []]),
    ]);

    $issues = app(MetaApi::class)->commentSetupIssues($this->link);

    expect($issues)->toHaveCount(3)
        ->and($issues[0]['detalle'])->toContain('pages_read_engagement')
        ->and($issues[1]['tipo'])->toBe('Webhook de la app')
        ->and($issues[1]['detalle'])->toBe('feed')
        ->and($issues[2]['tipo'])->toBe('Suscripción de la página')
        ->and($issues[2]['accion'])->toContain('Reparar suscripción');
});

it('con la conexión completa no reporta ningún paso pendiente', function () {
    config()->set('meta.app_id', 'APP1');
    config()->set('meta.app_secret', 'SECRET1');

    Http::fake([
        'graph.test/v21.0/debug_token*' => Http::response(['data' => [
            'scopes' => ['pages_messaging', 'pages_read_engagement', 'pages_manage_engagement'],
        ]]),
        'graph.test/v21.0/APP1/subscriptions*' => Http::response(['data' => [
            ['object' => 'page', 'fields' => [['name' => 'messages'], ['name' => 'feed']]],
        ]]),
        'graph.test/v21.0/PAGE123/subscribed_apps*' => Http::response(['data' => [
            ['subscribed_fields' => ['messages', 'messaging_postbacks', 'feed']],
        ]]),
        '*' => Http::response(['data' => []]),
    ]);

    expect(app(MetaApi::class)->commentSetupIssues($this->link))->toBe([]);
});

it('responde por el edge correcto de cada red', function () {
    // Bug real (2026-08-20, en vivo): en Facebook la respuesta a un
    // comentario es otro comentario suyo (/comments); /replies es de
    // Instagram, y con el edge equivocado Meta contesta "Object does not
    // exist" aunque el comentario esté ahí.
    Http::fake(['*' => Http::response(['id' => 'reply-1'])]);

    $api = app(MetaApi::class);

    $api->replyToComment($this->link, 'c1', 'Hola');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/c1/comments'));

    $instagram = MetaChannelLink::create([
        'tenant_id' => (string) tenant('id'),
        'type' => 'instagram',
        'external_id' => 'IG1',
        'access_token' => 'tok',
        'active' => true,
    ]);

    $api->replyToComment($instagram, 'ig_c1', 'Hola');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig_c1/replies'));
});

it('la reparación de la suscripción incluye feed', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    app(MetaApi::class)->resubscribe($this->link);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/PAGE123/subscribed_apps')
        && str_contains($request['subscribed_fields'] ?? '', 'feed'));
});

it('no inventa la alarma cuando el acceso sí trae los permisos', function () {
    config()->set('meta.app_id', 'APP1');
    config()->set('meta.app_secret', 'SECRET1');

    Http::fake([
        'graph.test/v21.0/debug_token*' => Http::response(['data' => [
            'scopes' => ['pages_messaging', 'pages_read_engagement', 'pages_manage_engagement'],
        ]]),
        '*' => Http::response(['data' => []]),
    ]);

    expect(app(MetaApi::class)->missingCommentPermissions($this->link))->toBe([]);
});

it('ante la duda no alarma: sin credenciales de app no afirma nada', function () {
    config()->set('meta.app_id', null);
    config()->set('meta.app_secret', null);

    expect(app(MetaApi::class)->missingCommentPermissions($this->link))->toBe([]);
});

it('el escaneo nombra la cuenta revisada y avisa si el token murió', function () {
    Http::fake([
        'graph.test/v21.0/PAGE123?*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400),
        'graph.test/*' => Http::response(['data' => []]),
    ]);

    app(\App\Http\Controllers\Tenant\SocialSyncController::class)
        ->store(Request::create('/redes/sincronizar', 'POST', ['meses' => 1]));

    expect(session('error'))->toContain('no se pudo leer la cuenta')
        ->and(session('error'))->toContain('vuelve a conectarla');
});

it('guarda publicaciones con URLs largas del CDN (bug real 2026-08-20)', function () {
    Http::fake([
        'graph.test/v21.0/PAGE123/posts*' => Http::response([
            'data' => [fbPost('p1', now()->subDays(3)->toIso8601String())],
        ]),
        // Comodín obligatorio: lo que no se finge, Laravel lo pide de verdad
        // (identidad de la cuenta, fotos, videos).
        '*' => Http::response(['data' => []]),
    ]);

    app(\App\Http\Controllers\Tenant\SocialSyncController::class)
        ->store(Request::create('/redes/sincronizar', 'POST', ['meses' => 1]));

    $post = SocialPost::first();

    expect($post)->not->toBeNull()
        // 255 caracteres no alcanzan: el CDN de Facebook ronda los 700.
        ->and(strlen((string) $post->media_url))->toBeGreaterThan(500)
        ->and($post->permalink)->toBe('https://facebook.com/p1');
});

it('no pide comentarios de publicaciones que no tienen ninguno', function () {
    Http::fake([
        'graph.test/v21.0/PAGE123/posts*' => Http::response([
            'data' => [fbPost('p1', now()->subDay()->toIso8601String(), comments: 0)],
        ]),
        'graph.test/*/comments*' => Http::response(['data' => []]),
        '*' => Http::response(['data' => []]),
    ]);

    app(\App\Http\Controllers\Tenant\SocialSyncController::class)
        ->store(Request::create('/redes/sincronizar', 'POST', ['meses' => 1]));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/p1/comments'));
});

it('trae los comentarios de una publicación que sí los tiene', function () {
    Http::fake([
        'graph.test/v21.0/PAGE123/posts*' => Http::response([
            'data' => [fbPost('p1', now()->subDay()->toIso8601String(), comments: 2)],
        ]),
        'graph.test/v21.0/p1/comments*' => Http::response([
            'data' => [
                [
                    'id' => 'c1', 'message' => 'Cuanto cuesta?',
                    'from' => ['id' => 'USER1', 'name' => 'Ana'],
                    'created_time' => now()->subHours(3)->toIso8601String(),
                ],
                // Nuestra propia respuesta no es un comentario que atender.
                [
                    'id' => 'c2', 'message' => 'Te mandamos privado',
                    'from' => ['id' => 'PAGE123', 'name' => 'Hotel'],
                    'created_time' => now()->subHours(2)->toIso8601String(),
                ],
            ],
        ]),
        '*' => Http::response(['data' => []]),
    ]);

    app(\App\Http\Controllers\Tenant\SocialSyncController::class)
        ->store(Request::create('/redes/sincronizar', 'POST', ['meses' => 1]));

    expect(SocialComment::count())->toBe(1)
        ->and(SocialComment::first()->author_name)->toBe('Ana')
        ->and(SocialComment::first()->status)->toBe(SocialComment::STATUS_NEW);
});

it('el índice filtra las publicaciones por el periodo pedido', function () {
    SocialPost::create([
        'network' => SocialPost::NETWORK_FACEBOOK, 'external_id' => 'reciente',
        'published_at' => now()->subDays(10),
    ]);
    SocialPost::create([
        'network' => SocialPost::NETWORK_FACEBOOK, 'external_id' => 'vieja',
        'published_at' => now()->subMonths(8),
    ]);

    $props = fn (int $meses) => (function ($response) {
        $property = (new ReflectionObject($response))->getProperty('props');
        $property->setAccessible(true);

        return $property->getValue($response);
    })(app(\App\Http\Controllers\Tenant\SocialPageController::class)(
        Request::create('/redes', 'GET', ['meses' => $meses]),
    ));

    expect($props(1)['posts'])->toHaveCount(1)
        ->and($props(1)['posts'][0]['excerpt'])->toContain('Publicación del')
        ->and($props(12)['posts'])->toHaveCount(2);
});

it('el escaneo dice con claridad qué cuenta revisó y que no encontró nada', function () {
    Http::fake([
        'graph.test/v21.0/PAGE123?*' => Http::response(['name' => 'Pagina del Hotel']),
        '*' => Http::response(['data' => []]),
    ]);

    $response = app(\App\Http\Controllers\Tenant\SocialSyncController::class)
        ->store(Request::create('/redes/sincronizar', 'POST', ['meses' => 3]));

    // Nombrar la cuenta es lo que delata haber conectado la página
    // equivocada, que es la causa real más común de "no jala nada".
    expect(session('error'))->toContain('Escaneo de los últimos 3 meses')
        ->and(session('error'))->toContain('Facebook (Pagina del Hotel)')
        ->and(session('error'))->toContain('sin publicaciones en el periodo')
        ->and(session('error'))->toContain('la página correcta')
        ->and($response->getStatusCode())->toBe(302);
});
