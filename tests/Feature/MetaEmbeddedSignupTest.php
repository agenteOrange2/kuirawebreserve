<?php

use App\Models\Central\MetaChannelLink;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Http;

function signupLink(array $overrides = []): MetaChannelLink
{
    return MetaChannelLink::create([
        'tenant_id' => 'demo',
        'type' => 'whatsapp',
        'external_id' => 'PN555',
        'waba_id' => 'WABA555',
        'access_token' => 'EAAG-negocio',
        'active' => true,
        ...$overrides,
    ]);
}

it('canjea el code del registro incrustado por el token del negocio', function () {
    config()->set('meta.app_id', '123');
    config()->set('meta.app_secret', 'secreto');

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'access_token' => 'EAAG-token-del-negocio',
        ]),
    ]);

    $token = app(MetaApi::class)->exchangeEmbeddedSignupCode('CODE-abc');

    expect($token)->toBe('EAAG-token-del-negocio');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/oauth/access_token')
        && str_contains($req->url(), 'code=CODE-abc')
        && str_contains($req->url(), 'client_id=123'));
});

it('code rechazado por Meta: devuelve null sin tronar', function () {
    config()->set('meta.app_id', '123');
    config()->set('meta.app_secret', 'secreto');

    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'expired code']], 400),
    ]);

    expect(app(MetaApi::class)->exchangeEmbeddedSignupCode('CODE-viejo'))->toBeNull();
});

it('registra el número en la Cloud API con el pin por defecto', function () {
    Http::fake(['graph.facebook.com/*/PN555/register' => Http::response(['success' => true])]);

    $ok = app(MetaApi::class)->registerNumber(signupLink());

    expect($ok)->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), '/PN555/register')
        && $req['messaging_product'] === 'whatsapp');
});

it('número ya registrado (coexistencia): el registro falla sin drama', function () {
    Http::fake([
        'graph.facebook.com/*/PN555/register' => Http::response(['error' => ['message' => 'already registered']], 400),
    ]);

    expect(app(MetaApi::class)->registerNumber(signupLink()))->toBeFalse();
});
