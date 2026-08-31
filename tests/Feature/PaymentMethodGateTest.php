<?php

use App\Models\Central\PaymentGatewayLink;
use App\Models\Central\PaymentMethodSetting;
use App\Services\Payments\PaymentMethodGate;

it('habilita todo por default (sin filas)', function () {
    $gate = app(PaymentMethodGate::class);

    expect($gate->platformEnabled('transfer'))->toBeTrue()
        ->and($gate->enabledFor('demo', 'stripe'))->toBeTrue()
        ->and($gate->methodsFor('demo'))->toBe([
            'transfer' => true, 'stripe' => true, 'mercadopago' => true, 'paypal' => true, 'cash' => true,
        ]);
});

it('el apagado global manda sobre el override del hotel', function () {
    $gate = app(PaymentMethodGate::class);

    $gate->set(null, 'mercadopago', false);
    $gate->set('demo', 'mercadopago', true);

    expect($gate->platformEnabled('mercadopago'))->toBeFalse()
        ->and($gate->enabledFor('demo', 'mercadopago'))->toBeFalse();
});

it('el override por hotel apaga solo a ese hotel', function () {
    $gate = app(PaymentMethodGate::class);

    $gate->set('demo', 'transfer', false);

    expect($gate->enabledFor('demo', 'transfer'))->toBeFalse()
        ->and($gate->enabledFor('palmas', 'transfer'))->toBeTrue();

    // Reencender limpia el efecto (updateOrCreate sobre la misma fila).
    $gate->set('demo', 'transfer', true);
    expect($gate->enabledFor('demo', 'transfer'))->toBeTrue()
        ->and(PaymentMethodSetting::query()->count())->toBe(1);
});

it('la pasarela para cobrar exige link activo Y método encendido', function () {
    $gate = app(PaymentMethodGate::class);

    PaymentGatewayLink::query()->create([
        'tenant_id' => 'demo',
        'provider' => 'stripe',
        'mode' => 'test',
        'public_key' => 'pk_test',
        'secret_key' => 'sk_test',
        'webhook_token' => 'tok_demo',
        'active' => true,
    ]);

    expect($gate->activeGatewayLink('demo')?->provider)->toBe('stripe');

    // Caso real (cabañas, 2026-08-28): el link seguía activo pero el hotel
    // había apagado las pasarelas, así que el panel ofrecía un cobro que el
    // endpoint rechazaba con 422.
    $gate->set('demo', 'stripe', false);
    expect($gate->activeGatewayLink('demo'))->toBeNull();

    $gate->set('demo', 'stripe', true);
    PaymentGatewayLink::query()->where('tenant_id', 'demo')->update(['active' => false]);
    expect($gate->activeGatewayLink('demo'))->toBeNull();
});

it('no confunde la pasarela de otro hotel con la propia', function () {
    PaymentGatewayLink::query()->create([
        'tenant_id' => 'palmas',
        'provider' => 'paypal',
        'mode' => 'test',
        'public_key' => 'pk',
        'secret_key' => 'sk',
        'webhook_token' => 'tok_palmas',
        'active' => true,
    ]);

    expect(app(PaymentMethodGate::class)->activeGatewayLink('demo'))->toBeNull();
});
