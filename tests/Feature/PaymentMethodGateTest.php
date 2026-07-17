<?php

use App\Models\Central\PaymentMethodSetting;
use App\Services\Payments\PaymentMethodGate;

it('habilita todo por default (sin filas)', function () {
    $gate = app(PaymentMethodGate::class);

    expect($gate->platformEnabled('transfer'))->toBeTrue()
        ->and($gate->enabledFor('demo', 'stripe'))->toBeTrue()
        ->and($gate->methodsFor('demo'))->toBe([
            'transfer' => true, 'stripe' => true, 'mercadopago' => true, 'paypal' => true,
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
