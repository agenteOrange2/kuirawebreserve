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

it('spam: se oculta solo si el hotel activó la moderación, y siempre queda auditado', function () {
    Http::fake(['graph.test/*' => Http::response(['success' => true])]);

    $spam = ['clasificacion' => SocialComment::CLASS_SPAM, 'respuesta_publica' => '', 'mensaje_privado' => ''];

    // Sin moderación automática: espera a una persona.
    $sinModeracion = socialComment();
    socialResponder($spam)->handle($sinModeracion->fresh(), socialLink());
    expect($sinModeracion->fresh()->status)->toBe(SocialComment::STATUS_PENDING_STAFF);

    (new SocialSettings)->save(['activo' => true, 'moderacion_automatica' => true]);

    $conModeracion = socialComment();
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

    $comment = socialComment();
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
