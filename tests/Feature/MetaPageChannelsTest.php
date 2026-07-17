<?php

use App\Models\Central\MetaChannelLink;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Http;

function metaLink(array $overrides = []): MetaChannelLink
{
    return MetaChannelLink::create([
        'tenant_id' => 'demo',
        'type' => 'messenger',
        'external_id' => 'PAGE123',
        'access_token' => 'token-abc',
        'active' => true,
        ...$overrides,
    ]);
}

it('diagnostica una página de Messenger: identidad y suscripción con campos', function () {
    Http::fake([
        'graph.facebook.com/*/PAGE123?*' => Http::response(['name' => 'Hotel Demo Fan Page']),
        'graph.facebook.com/*/PAGE123/subscribed_apps' => Http::response([
            'data' => [['name' => 'KuiraWebReserve', 'subscribed_fields' => ['messages', 'messaging_postbacks']]],
        ]),
    ]);

    $result = app(MetaApi::class)->diagnose(metaLink());

    expect($result['token_ok'])->toBeTrue()
        ->and($result['identity'])->toBe('Hotel Demo Fan Page')
        ->and($result['subscribed'])->toBe(['KuiraWebReserve'])
        ->and($result['subscribed_fields'])->toContain('messages');
});

it('en Instagram la suscripción requiere el page_id vinculado (campo waba_id)', function () {
    Http::fake([
        'graph.facebook.com/*/IG456?*' => Http::response(['username' => 'hoteldemo', 'name' => 'Hotel Demo']),
    ]);

    $sinPagina = app(MetaApi::class)->diagnose(metaLink(['type' => 'instagram', 'external_id' => 'IG456']));

    expect($sinPagina['token_ok'])->toBeTrue()
        ->and($sinPagina['identity'])->toBe('Hotel Demo · hoteldemo')
        ->and($sinPagina['subscribed'])->toBeNull(); // sin page_id no hay cómo verificar
});

it('repara la suscripción de una página con el campo messages', function () {
    Http::fake([
        'graph.facebook.com/*/PAGE123/subscribed_apps' => Http::response(['success' => true]),
    ]);

    $ok = app(MetaApi::class)->resubscribe(metaLink());

    expect($ok)->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/PAGE123/subscribed_apps')
        && str_contains($request['subscribed_fields'] ?? '', 'messages'));
});

it('la reparación de Instagram usa la página vinculada, no la cuenta', function () {
    Http::fake([
        'graph.facebook.com/*/PAGE123/subscribed_apps' => Http::response(['success' => true]),
    ]);

    $link = metaLink(['type' => 'instagram', 'external_id' => 'IG456', 'waba_id' => 'PAGE123']);

    expect(app(MetaApi::class)->resubscribe($link))->toBeTrue();

    // Y sin page_id capturado no intenta nada.
    expect(app(MetaApi::class)->resubscribe(metaLink(['type' => 'instagram', 'external_id' => 'IG789', 'waba_id' => null])))->toBeFalse();
});

it('Instagram Login (token IGAA) envía por graph.instagram.com y diagnostica /me', function () {
    Http::fake([
        'graph.instagram.com/*/me/messages' => Http::response(['message_id' => 'mid.1']),
        'graph.instagram.com/*/me?*' => Http::response(['username' => 'pingumalakial', 'name' => 'Mau Roman']),
        // Primera llamada = GET del diagnóstico; segunda = POST del resubscribe.
        'graph.instagram.com/*/me/subscribed_apps' => Http::sequence()
            ->push(['data' => [['id' => '18053307242541042', 'subscribed_fields' => ['messages']]]])
            ->push(['success' => true]),
    ]);

    $link = metaLink(['type' => 'instagram', 'external_id' => '178414462312', 'access_token' => 'IGAAM840token']);
    $api = app(MetaApi::class);

    expect($api->sendText($link, 'IGSID9', 'Hola desde el hotel'))->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.instagram.com')
        && str_contains($request->url(), '/me/messages')
        && $request['recipient']['id'] === 'IGSID9');

    $diagnose = $api->diagnose($link);
    expect($diagnose['token_ok'])->toBeTrue()
        ->and($diagnose['identity'])->toBe('Mau Roman · pingumalakial')
        ->and($diagnose['subscribed_fields'])->toContain('messages');

    expect($api->resubscribe($link))->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.instagram.com')
        && str_contains($request->url(), '/me/subscribed_apps'));
});

it('acepta la firma del webhook con cualquiera de los dos app secrets', function () {
    config()->set('meta.app_secret', 'fb-secret');
    config()->set('meta.ig_app_secret', 'ig-secret');

    $payload = json_encode(['object' => 'instagram', 'entry' => []]);

    $firmar = fn (string $secret) => $this->call(
        'POST', '/webhooks/meta', [], [], [],
        ['HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $secret), 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    expect($firmar('fb-secret')->getStatusCode())->toBe(200)
        ->and($firmar('ig-secret')->getStatusCode())->toBe(200)
        ->and($firmar('secreto-falso')->getStatusCode())->toBe(401);
});

it('normaliza los DMs de Instagram Login que llegan en formato changes', function () {
    $entry = ['id' => '17841446231238020'];
    $change = ['field' => 'messages', 'value' => [
        'sender' => ['id' => 'IGSID_HUESPED'],
        'recipient' => ['id' => '17841446231238020'],
        'message' => ['mid' => 'mid.abc', 'text' => 'Hola, necesito info por favor'],
    ]];

    $normalized = \App\Http\Controllers\Webhooks\MetaWebhookController::instagramChangeToMessage($entry, $change);

    expect($normalized)->not->toBeNull()
        ->and($normalized['from'])->toBe('IGSID_HUESPED')
        ->and($normalized['body'])->toBe('Hola, necesito info por favor')
        ->and($normalized['external_id'])->toBe('mid.abc');

    // Ecos (la cuenta hablando) y campos ajenos se descartan.
    expect(\App\Http\Controllers\Webhooks\MetaWebhookController::instagramChangeToMessage(
        $entry,
        ['field' => 'messages', 'value' => ['sender' => ['id' => '17841446231238020'], 'message' => ['text' => 'eco']]],
    ))->toBeNull();
    expect(\App\Http\Controllers\Webhooks\MetaWebhookController::instagramChangeToMessage(
        $entry,
        ['field' => 'comments', 'value' => []],
    ))->toBeNull();
});

it('el formato changes de Instagram ya no se confunde con WhatsApp', function () {
    config()->set('meta.app_secret', '');
    config()->set('meta.ig_app_secret', '');

    // object=instagram con changes: antes entraba al branch de WhatsApp y se
    // tragaba en silencio; ahora responde ok y (sin canal vinculado) lo loguea.
    $payload = ['object' => 'instagram', 'entry' => [[
        'id' => 'IG_DESCONOCIDO',
        'changes' => [['field' => 'messages', 'value' => [
            'sender' => ['id' => 'IGSID1'],
            'message' => ['mid' => 'mid.1', 'text' => 'Hola'],
        ]]],
    ]]];

    $this->postJson('/webhooks/meta', $payload)->assertOk();
});

it('obtiene el nombre del contacto en Messenger e Instagram (best-effort)', function () {
    Http::fake([
        'graph.facebook.com/*/PSID1?*' => Http::response(['name' => 'Maria Lopez']),
        'graph.facebook.com/*/IGSID2?*' => Http::response(['username' => 'maria.viajes']),
    ]);

    $api = app(MetaApi::class);

    expect($api->contactName(metaLink(), 'PSID1'))->toBe('Maria Lopez')
        ->and($api->contactName(metaLink(['type' => 'instagram', 'external_id' => 'IG456']), 'IGSID2'))->toBe('maria.viajes')
        ->and($api->contactName(metaLink(['type' => 'whatsapp', 'external_id' => 'PHONE1']), '521614'))->toBeNull();
});
