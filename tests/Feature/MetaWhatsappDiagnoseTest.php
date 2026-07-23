<?php

use App\Models\Central\MetaChannelLink;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Http;

function whatsappLink(array $overrides = []): MetaChannelLink
{
    return MetaChannelLink::create([
        'tenant_id' => 'demo',
        'type' => 'whatsapp',
        'external_id' => 'PN123',
        'waba_id' => 'WABA9',
        'access_token' => 'EAAG-token',
        'active' => true,
        ...$overrides,
    ]);
}

it('probar conexión de WhatsApp: token vivo, número y callback correcto', function () {
    Http::fake([
        'graph.facebook.com/*/PN123?*' => Http::response([
            'display_phone_number' => '+52 656 850 8818',
            'verified_name' => 'Cabañas Real',
            'quality_rating' => 'GREEN',
            'webhook_configuration' => ['application' => route('webhooks.meta')],
        ]),
        'graph.facebook.com/*/WABA9/subscribed_apps' => Http::response([
            'data' => [['name' => 'KuiraWebReserve']],
        ]),
    ]);

    $result = app(MetaApi::class)->diagnose(whatsappLink());

    expect($result['token_ok'])->toBeTrue()
        ->and($result['phone'])->toContain('656 850 8818')
        ->and($result['quality'])->toBe('GREEN')
        ->and($result['callback_ok'])->toBeTrue();
});

it('token inválido: la conexión falla sin tronar', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth token']], 401),
    ]);

    $result = app(MetaApi::class)->diagnose(whatsappLink());

    expect($result['token_ok'])->toBeFalse();
});

it('el token se guarda cifrado y se enmascara', function () {
    $link = whatsappLink(['access_token' => 'EAAG-supersecreto-123456']);

    expect($link->access_token)->toBe('EAAG-supersecreto-123456') // descifrado al leer
        ->and($link->getRawOriginal('access_token'))->not->toBe('EAAG-supersecreto-123456') // cifrado en BD
        ->and($link->maskedToken())->toBe('••••123456');
});

it('el envío de WhatsApp pega al phone_number_id con el token', function () {
    Http::fake(['graph.facebook.com/*/PN123/messages' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

    $ok = app(MetaApi::class)->sendText(whatsappLink(), '5216561234567', 'Hola');

    expect($ok)->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), '/PN123/messages')
        && $req['messaging_product'] === 'whatsapp');
});
