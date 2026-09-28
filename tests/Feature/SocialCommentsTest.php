<?php

use App\Jobs\ProcessSocialComment;
use App\Models\Central\MetaChannelLink;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Models\StaffNotification;
use App\Services\Agent\AgentBrain;
use App\Services\Social\SocialCommentClassifier;
use App\Services\Social\SocialResponder;
use App\Services\Social\SocialSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();

    config()->set('meta.graph_url', 'https://graph.test/v21.0');
    config()->set('meta.app_secret', null);
    config()->set('meta.ig_app_secret', null);
    config()->set('meta.mode', 'test'); // sin secretos, la firma se omite
});

function socialLink(array $overrides = []): MetaChannelLink
{
    $attributes = [
        'tenant_id' => 'demo',
        'type' => 'messenger',
        'external_id' => 'PAGE123',
        'access_token' => 'token-abc',
        'active' => true,
        ...$overrides,
    ];

    return MetaChannelLink::firstOrCreate(
        ['type' => $attributes['type'], 'external_id' => $attributes['external_id']],
        $attributes,
    );
}

function socialComment(array $overrides = []): SocialComment
{
    $post = SocialPost::firstOrCreate(
        ['network' => SocialPost::NETWORK_FACEBOOK, 'external_id' => 'PAGE123_p1'],
        ['account_external_id' => 'PAGE123', 'message' => 'Promoción de fin de semana en nuestras suites'],
    );

    return SocialComment::create([
        'social_post_id' => $post->id,
        'external_id' => 'PAGE123_c'.fake()->unique()->numberBetween(1, 9999),
        'author_external_id' => 'USER9',
        'author_name' => 'Ana Ruiz',
        'body' => '¿Cuánto cuesta la suite con jacuzzi?',
        'commented_at' => now(),
        'status' => SocialComment::STATUS_NEW,
        ...$overrides,
    ]);
}

/**
 * Responder con un clasificador de laboratorio: la decisión que se prueba es
 * la del módulo (qué hacer con cada categoría), no la del modelo.
 *
 * @param  array{clasificacion: string, respuesta_publica: string, mensaje_privado: string}|null  $result
 */
function socialResponder(?array $result): SocialResponder
{
    $classifier = new class($result) extends SocialCommentClassifier
    {
        public function __construct(protected ?array $canned) {}

        public function classify(SocialPost $post, SocialComment $comment): ?array
        {
            return $this->canned ? $this->canned + ['meta' => ['provider' => 'test', 'model' => 'test']] : null;
        }
    };

    $brain = new class extends AgentBrain
    {
        public function __construct() {}

        public function isConfigured(): bool
        {
            return true;
        }
    };

    return new SocialResponder(
        app(\App\Services\Meta\MetaApi::class),
        $classifier,
        $brain,
        app(\App\Services\StaffNotifier::class),
    );
}

it('el webhook encola los comentarios en vez de contestarlos en el request', function () {
    Queue::fake();
    socialLink();

    $response = $this->postJson('/webhooks/meta', [
        'object' => 'page',
        'entry' => [[
            'id' => 'PAGE123',
            'changes' => [[
                'field' => 'feed',
                'value' => [
                    'item' => 'comment', 'verb' => 'add',
                    'comment_id' => 'PAGE123_c1', 'post_id' => 'PAGE123_p1',
                    'from' => ['id' => 'USER9', 'name' => 'Ana Ruiz'],
                    'message' => '¿Precios?',
                ],
            ]],
        ]],
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessSocialComment::class, fn ($job) => $job->tenantId === 'demo'
        && $job->payload['comment_id'] === 'PAGE123_c1');
});

it('un comentario de una página no vinculada no encola nada', function () {
    Queue::fake();

    $this->postJson('/webhooks/meta', [
        'object' => 'page',
        'entry' => [[
            'id' => 'PAGINA_AJENA',
            'changes' => [[
                'field' => 'feed',
                'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => 'x', 'from' => ['id' => 'U']],
            ]],
        ]],
    ])->assertOk();

    Queue::assertNothingPushed();
});

it('intención de compra: responde en público, manda privado y abre la conversación', function () {
    Http::fake([
        'graph.test/*/me/messages' => Http::response(['message_id' => 'mid.1', 'recipient_id' => 'PSID77']),
        // En Facebook la respuesta pública va por el edge /comments.
        'graph.test/*/comments' => Http::response(['id' => 'reply-1']),
    ]);

    $comment = socialComment();

    socialResponder([
        'clasificacion' => SocialComment::CLASS_PURCHASE,
        'respuesta_publica' => 'Con gusto te mandamos la info por privado.',
        'mensaje_privado' => 'Hola Ana, vimos tu comentario. Te ayudo con tarifas y disponibilidad.',
    ])->handle($comment->fresh(), socialLink());

    $comment->refresh();

    expect($comment->classification)->toBe(SocialComment::CLASS_PURCHASE)
        ->and($comment->status)->toBe(SocialComment::STATUS_ANSWERED)
        ->and($comment->public_reply_external_id)->toBe('reply-1')
        ->and($comment->private_reply_sent_at)->not->toBeNull()
        ->and($comment->conversation_id)->not->toBeNull();

    // La conversación queda con el PSID que devolvió el Send API: es la
    // misma llave del webhook de DMs, así su respuesta cae en este hilo.
    $conversation = Conversation::find($comment->conversation_id);
    expect($conversation->contact_phone)->toBe('PSID77')
        ->and($conversation->contact_name)->toBe('Ana Ruiz')
        ->and($conversation->lead_status)->toBe(Conversation::LEAD_QUOTING)
        ->and($conversation->messages()->count())->toBe(1);
});

it('una queja nunca se responde sola: queda para el staff y suena la campana', function () {
    Http::fake();

    $comment = socialComment(['body' => 'Pésimo servicio, llevo una hora esperando']);

    socialResponder([
        'clasificacion' => SocialComment::CLASS_COMPLAINT,
        'respuesta_publica' => 'Lo sentimos mucho',
        'mensaje_privado' => 'Cuéntanos qué pasó',
    ])->handle($comment->fresh(), socialLink());

    $comment->refresh();

    expect($comment->status)->toBe(SocialComment::STATUS_PENDING_STAFF)
        ->and($comment->public_replied_at)->toBeNull()
        ->and($comment->private_reply_sent_at)->toBeNull();

    Http::assertNothingSent();
    expect(StaffNotification::where('type', 'social')->count())->toBe(1);
});

it('queja con plantilla activada: publica el texto fijo con el nombre, sin privado, y sigue pendiente', function () {
    Http::fake([
        'graph.test/*/comments' => Http::response(['id' => 'reply-q1']),
    ]);

    // El hotel activa la respuesta pública de quejas con SU plantilla.
    (new SocialSettings)->save([
        'activo' => true,
        'clasificaciones' => [
            SocialComment::CLASS_COMPLAINT => [
                'responder_publico' => true,
                // El privado se manda como true a propósito: la regla dura
                // debe ignorarlo aunque venga activado en el payload.
                'mandar_privado' => true,
                'plantilla' => 'Hola [Nombre], lamentamos tu experiencia. Escríbenos por privado para revisarlo.',
            ],
        ],
    ]);

    $comment = socialComment(['body' => 'No lo recomiendo, pésima atención']);

    socialResponder([
        'clasificacion' => SocialComment::CLASS_COMPLAINT,
        'respuesta_publica' => 'Texto de la IA que NO debe publicarse',
        'mensaje_privado' => 'Privado que NO debe mandarse',
    ])->handle($comment->fresh(), socialLink());

    $comment->refresh();

    // Publica la plantilla personalizada, jamás lo que redacte la IA.
    expect($comment->public_reply_external_id)->toBe('reply-q1')
        ->and($comment->public_reply_text)->toBe('Hola Ana Ruiz, lamentamos tu experiencia. Escríbenos por privado para revisarlo.')
        ->and($comment->private_reply_sent_at)->toBeNull()
        ->and($comment->conversation_id)->toBeNull()
        // Aunque respondió, una persona debe dar seguimiento.
        ->and($comment->status)->toBe(SocialComment::STATUS_PENDING_STAFF);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'me/messages'));
    expect(StaffNotification::where('type', 'social')->count())->toBe(1);
});

it('el placeholder [Nombre] se limpia cuando el comentarista no trae nombre', function () {
    expect(SocialSettings::personalize('Hola [Nombre], gracias por escribirnos.', null))
        ->toBe('Hola, gracias por escribirnos.')
        ->and(SocialSettings::personalize('[Nombre] ¡Qué alegría leerte!', ''))
        ->toBe('¡Qué alegría leerte!')
        ->and(SocialSettings::personalize('Hola [Nombre], bienvenida.', 'Lili Rios'))
        ->toBe('Hola Lili Rios, bienvenida.');
});

it('spam: se oculta solo si el hotel activó la moderación, y siempre queda auditado', function () {
    Http::fake(['graph.test/*' => Http::response(['success' => true])]);

    $spam = ['clasificacion' => SocialComment::CLASS_SPAM, 'respuesta_publica' => '', 'mensaje_privado' => ''];

    // Sin moderación automática: espera a una persona.
    $sinModeracion = socialComment(['body' => 'Visiten mi página de ofertas']);
    socialResponder($spam)->handle($sinModeracion->fresh(), socialLink());
    expect($sinModeracion->fresh()->status)->toBe(SocialComment::STATUS_PENDING_STAFF);

    (new SocialSettings)->save(['activo' => true, 'moderacion_automatica' => true]);

    $conModeracion = socialComment(['body' => 'Visiten mi página de ofertas']);
    socialResponder($spam)->handle($conModeracion->fresh(), socialLink());

    $conModeracion->refresh();
    expect($conModeracion->status)->toBe(SocialComment::STATUS_HIDDEN)
        ->and($conModeracion->hidden_at)->not->toBeNull()
        ->and($conModeracion->hidden_reason)->toContain('spam');

    Http::assertSent(fn ($request) => str_contains($request->url(), $conModeracion->external_id)
        && ($request['is_hidden'] ?? null) === 'true');
});

it('una palabra bloqueada por el hotel se oculta sin gastar una llamada de IA', function () {
    Http::fake(['graph.test/*' => Http::response(['success' => true])]);

    (new SocialSettings)->save([
        'activo' => true,
        'moderacion_automatica' => true,
        'palabras_bloqueadas' => ['visita mi perfil'],
    ]);

    $comment = socialComment(['body' => 'VISITA MI PERFIL para ganar dinero']);

    // El clasificador devolvería null (fallo), pero ni se le llama.
    socialResponder(null)->handle($comment->fresh(), socialLink());

    $comment->refresh();
    expect($comment->status)->toBe(SocialComment::STATUS_HIDDEN)
        ->and($comment->classification)->toBeNull()
        ->and($comment->hidden_reason)->toContain('visita mi perfil');
});

it('un comentario sin texto va al staff sin gastar una llamada de IA', function () {
    // Caso real (motellacupula, 2026-08-20): sin pages_read_engagement Meta
    // manda el evento SIN el texto, y clasificar el vacío lo marcaba spam.
    Http::fake();

    $comment = socialComment(['body' => '']);

    socialResponder([
        'clasificacion' => SocialComment::CLASS_SPAM,
        'respuesta_publica' => '',
        'mensaje_privado' => '',
    ])->handle($comment->fresh(), socialLink());

    $comment->refresh();

    expect($comment->status)->toBe(SocialComment::STATUS_PENDING_STAFF)
        ->and($comment->classification)->toBeNull()
        ->and($comment->hidden_at)->toBeNull();

    Http::assertNothingSent();
    expect(StaffNotification::where('type', 'social')->count())->toBe(1);
});

it('si el clasificador falla, el comentario va al staff en vez de inventar respuesta', function () {
    Http::fake();

    $comment = socialComment(['body' => 'Hola saludos']);
    socialResponder(null)->handle($comment->fresh(), socialLink());

    expect($comment->fresh()->status)->toBe(SocialComment::STATUS_PENDING_STAFF);
    Http::assertNothingSent();
});

it('Meta reintenta el webhook: el mismo comentario no se contesta dos veces', function () {
    $comment = socialComment(['status' => SocialComment::STATUS_ANSWERED]);

    $job = new ProcessSocialComment('demo', socialLink()->id, [
        'network' => SocialPost::NETWORK_FACEBOOK,
        'verb' => 'add',
        'comment_id' => $comment->external_id,
        'post_id' => 'PAGE123_p1',
        'body' => '¿Cuánto cuesta la suite con jacuzzi?',
    ]);

    // El tenant no existe en el entorno de pruebas: el job sale antes de
    // tocar nada, que es justo lo que debe pasar con un tenant desconocido.
    $job->handle(app(\App\Services\Meta\MetaApi::class), socialResponder(null));

    expect(SocialComment::count())->toBe(1)
        ->and($comment->fresh()->status)->toBe(SocialComment::STATUS_ANSWERED);
});

it('el mensaje privado solo se puede mandar una vez y dentro de los 7 días', function () {
    $reciente = socialComment(['commented_at' => now()->subDay()]);
    $viejo = socialComment(['commented_at' => now()->subDays(8)]);
    $yaEnviado = socialComment(['commented_at' => now(), 'private_reply_sent_at' => now()]);

    expect($reciente->canPrivateReply())->toBeTrue()
        ->and($viejo->canPrivateReply())->toBeFalse()
        ->and($yaEnviado->canPrivateReply())->toBeFalse();
});

// ---------------------------------------------- lo evidente, sin IA
//
// Revisión de cabañas 2026-09-28: el mismo "Inf" salió como compra, elogio y
// spam; etiquetar a un amigo caía en elogio ("gracias por recomendarnos"),
// spam (50 pendientes) o compra (privado no pedido). Estos textos son reales.

it('las reglas reconocen los pedidos de información por cortos que sean', function (string $body) {
    expect((new \App\Services\Social\SocialIntentRules)->classify($body))->toBe(SocialComment::CLASS_PURCHASE);
})->with([
    'Inf', 'Inf.', 'Inbox', 'Información', 'Informes', 'info porfavor', 'Precio xf', 'Presio 4 personas',
    '$$', 'Cuánto cobran amigo ??', 'Rebeca Estrada  donde es', 'Yo me quiero ospedar',
    'Cabañas Real de la Sierra que precio tienen', 'Fechas disponibles', 'Me interesa',
]);

it('las reglas reconocen etiquetas a amigos y respuestas a la dinámica', function (string $body) {
    expect((new \App\Services\Social\SocialIntentRules)->classify($body))->toBe(SocialComment::CLASS_TAG);
})->with([
    'Karla Franco', 'Cristina Saucedo Yvette Trevizo Pamo Zuzana', 'Moro Compas', 'Joe LG❤️',
    'Raquel Alvarado siii Vamos 🤗', 'Mari Esparza vamos', 'Magali Barrera hay que ir bebe 🥺🥺🥺❤️',
    'Elizabeth Camacho 😍 si voy me llevas', '3', 'opción 2🙂',
]);

it('lo dudoso o las quejas siguen yendo a la IA', function (string $body) {
    expect((new \App\Services\Social\SocialIntentRules)->classify($body))->toBeNull();
})->with([
    'Hermoso lugar', 'No contestan les marco y marco y no contestan para reservar',
    'Qué tan cierto es que están carísimas ?', 'Hola saludos', 'LA RIFA PARA CUANDO ES ?????👀',
    'Me encantaria pero ya se metio la pache pache ala alberca nesecirarian desinfectar',
    'Es donde empieza lo bonito de un viaje, en carretera.', 'Yo', 'Margie Garcia Cisneros que bonito, quiero ir !!!',
]);

it('una etiqueta a un amigo no se contesta, no va al personal y no gasta IA', function () {
    Http::fake();
    $comment = socialComment(['body' => 'Karla Franco']);

    // Con clasificador nulo: si se le preguntara, el comentario iría al staff.
    socialResponder(null)->handle($comment->fresh(), socialLink());

    $comment->refresh();
    expect($comment->classification)->toBe(SocialComment::CLASS_TAG)
        ->and($comment->status)->toBe(SocialComment::STATUS_IGNORED)
        ->and(StaffNotification::count())->toBe(0);
    Http::assertNothingSent();
});

it('un "Inf" es compra aunque el modelo diga spam, y lleva privado fijo', function () {
    Http::fake([
        'graph.test/*/me/messages' => Http::response(['message_id' => 'mid.1', 'recipient_id' => 'PSID88']),
        'graph.test/*/comments' => Http::response(['id' => 'reply-2']),
    ]);
    $comment = socialComment(['body' => 'Inf']);

    socialResponder([
        'clasificacion' => SocialComment::CLASS_SPAM,
        'respuesta_publica' => '',
        'mensaje_privado' => '',
    ])->handle($comment->fresh(), socialLink());

    $comment->refresh();
    expect($comment->classification)->toBe(SocialComment::CLASS_PURCHASE)
        ->and($comment->status)->toBe(SocialComment::STATUS_ANSWERED)
        ->and($comment->private_reply_sent_at)->not->toBeNull();

    $privado = Conversation::find($comment->conversation_id)->messages()->first()->body;
    expect($privado)->toContain('Hola Ana')
        ->and($privado)->toContain('fechas');
});

it('si ya platica por privado, un segundo comentario no le manda otro saludo', function () {
    Http::fake([
        'graph.test/*/me/messages' => Http::response(['message_id' => 'mid.1', 'recipient_id' => 'PSID77']),
        'graph.test/*/comments' => Http::response(['id' => 'reply-3']),
    ]);
    $compra = [
        'clasificacion' => SocialComment::CLASS_PURCHASE,
        'respuesta_publica' => '',
        'mensaje_privado' => 'Hola Ana, ¿para qué fechas?',
    ];

    $primero = socialComment(['body' => 'Precio']);
    socialResponder($compra)->handle($primero->fresh(), socialLink());

    $segundo = socialComment(['body' => 'Precio por favor']);
    socialResponder($compra)->handle($segundo->fresh(), socialLink());

    $segundo->refresh();
    expect($segundo->conversation_id)->toBe($primero->fresh()->conversation_id)
        ->and($segundo->private_reply_sent_at)->toBeNull()
        ->and($segundo->status)->toBe(SocialComment::STATUS_ANSWERED)
        ->and(Conversation::find($segundo->conversation_id)->messages()->count())->toBe(1);
    Http::assertSentCount(3); // 2 respuestas públicas + 1 solo privado
});

// ---------------------------------------------- elogio, liga y reintento

it('solo se agradece en público un elogio que habla bien del lugar', function (string $body, bool $safe) {
    expect((new \App\Services\Social\SocialIntentRules)->praiseIsSafe($body))->toBe($safe);
})->with([
    ['Hermoso lugar', true],
    ['Yo los visité y la pasé súper bien muy lindas sus cabañas.', true],
    ['100% recomendada, a pesar del mal clima, el cual nos recompensaron con un paseo gratis', true],
    ['Me encantaria pero ya se metio la pache pache ala alberca nesecirarian desinfectar', false],
    ['Si por mi 😃 jejejeje', false],
    ['Cabañas Real de la Sierra ok', false],
]);

it('un elogio con "pero" no se contesta en público', function () {
    Http::fake();
    (new SocialSettings)->save(['activo' => true, 'clasificaciones' => [
        SocialComment::CLASS_PRAISE => ['responder_publico' => true, 'mandar_privado' => false, 'plantilla' => '¡Gracias por recomendarnos!'],
    ]]);
    $comment = socialComment(['body' => 'Me encantaría pero ya se metió la pache pache a la alberca']);

    socialResponder([
        'clasificacion' => SocialComment::CLASS_PRAISE,
        'respuesta_publica' => '',
        'mensaje_privado' => '',
    ])->handle($comment->fresh(), socialLink());

    expect($comment->fresh()->status)->toBe(SocialComment::STATUS_IGNORED)
        ->and($comment->fresh()->public_replied_at)->toBeNull();
    Http::assertNothingSent();
});

it('el privado de compra lleva la liga del wizard una sola vez', function () {
    $url = 'https://cabanas.test/reservar';

    expect(SocialResponder::withBookingLink('Hola, ¿para qué fechas?', $url))
        ->toBe('Hola, ¿para qué fechas? Si prefieres, aquí puedes ver fechas y apartar en línea: '.$url)
        ->and(SocialResponder::withBookingLink('Reserva en '.$url, $url))->toBe('Reserva en '.$url)
        ->and(SocialResponder::withBookingLink('Hola', null))->toBe('Hola');
});

it('reintenta el privado cuando Meta falla de su lado', function () {
    Http::fake([
        'graph.test/*/me/messages' => Http::sequence()
            ->push(['error' => ['code' => 1, 'message' => 'Please reduce the amount of data you\'re asking for, then retry your request']], 500)
            ->push(['message_id' => 'mid.9', 'recipient_id' => 'PSID9']),
    ]);

    $sent = app(\App\Services\Meta\MetaApi::class)->privateReply(socialLink(), 'PAGE123_c1', 'Hola');

    expect($sent['recipient_id'] ?? null)->toBe('PSID9');
    Http::assertSentCount(2);
});

it('no reintenta cuando el usuario no admite respuesta', function () {
    Http::fake([
        'graph.test/*/me/messages' => Http::response(['error' => [
            'code' => 10903, 'error_subcode' => 1893049, 'is_transient' => false, 'message' => 'This user cant reply to this activity',
        ]], 400),
    ]);

    expect(app(\App\Services\Meta\MetaApi::class)->privateReply(socialLink(), 'PAGE123_c1', 'Hola'))->toBeNull();
    Http::assertSentCount(1);
});
