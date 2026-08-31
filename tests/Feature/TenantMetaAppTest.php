<?php

use App\Models\Central\TenantMetaApp;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Http;

function ownApp(array $overrides = []): TenantMetaApp
{
    return TenantMetaApp::create([
        'tenant_id' => 'cabanas',
        'app_id' => '111000111',
        'app_secret' => 'secreto-cabanas',
        'ig_app_secret' => 'ig-secreto-cabanas',
        'login_config_id' => '555000555',
        'name' => 'App Cabanas',
        ...$overrides,
    ]);
}

it('resuelve las credenciales de la app propia del tenant', function () {
    config()->set('meta.app_id', '999');
    config()->set('meta.app_secret', 'plataforma');
    ownApp();

    $creds = app(MetaApi::class)->appCredsFor('cabanas');

    expect($creds['app_id'])->toBe('111000111')
        ->and($creds['app_secret'])->toBe('secreto-cabanas')
        ->and($creds['ig_app_secret'])->toBe('ig-secreto-cabanas')
        ->and($creds['login_config_id'])->toBe('555000555');
});

it('sin app propia cae a la app de la plataforma', function () {
    config()->set('meta.app_id', '999');
    config()->set('meta.app_secret', 'plataforma');
    config()->set('meta.login_config_id', 'cfg-plataforma');

    $creds = app(MetaApi::class)->appCredsFor('motel');

    expect($creds['app_id'])->toBe('999')
        ->and($creds['app_secret'])->toBe('plataforma')
        ->and($creds['login_config_id'])->toBe('cfg-plataforma');
});

it('el webhook acepta la firma de la app propia de un tenant', function () {
    config()->set('meta.app_secret', 'fb-secret');
    config()->set('meta.ig_app_secret', 'ig-secret');
    ownApp();

    $payload = json_encode(['object' => 'page', 'entry' => []]);

    $firmar = fn (string $secret) => $this->call(
        'POST', '/webhooks/meta', [], [], [],
        ['HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $secret), 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    expect($firmar('secreto-cabanas')->getStatusCode())->toBe(200)
        ->and($firmar('ig-secreto-cabanas')->getStatusCode())->toBe(200)
        ->and($firmar('fb-secret')->getStatusCode())->toBe(200)
        ->and($firmar('secreto-falso')->getStatusCode())->toBe(401);
});

it('el canje del registro incrustado usa la app del tenant', function () {
    config()->set('meta.app_id', '999');
    config()->set('meta.app_secret', 'plataforma');
    ownApp();

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAG-x']),
    ]);

    app(MetaApi::class)->exchangeEmbeddedSignupCode('CODE-1', 'cabanas');

    Http::assertSent(fn ($req) => str_contains($req->url(), 'client_id=111000111'));
});

it('los secretos de la app propia se guardan cifrados', function () {
    $app = ownApp();

    expect($app->app_secret)->toBe('secreto-cabanas')
        ->and($app->getRawOriginal('app_secret'))->not->toBe('secreto-cabanas')
        ->and($app->maskedSecret('app_secret'))->toBe('••••anas');
});
