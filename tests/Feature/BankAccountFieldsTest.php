<?php

use App\Http\Controllers\Tenant\PropertyController;
use App\Models\Property;
use App\Services\Payments\ReceiptCheck;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * La cuenta del hotel dejó de ser "un número" para ser tres: CLABE, tarjeta y
 * número de cuenta. Caso real cabañas: lo guardado era la TARJETA y todo el
 * sistema la anunciaba como "cuenta", así que quien la capturaba en su app
 * como cuenta recibía "número inválido".
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
});

function guardarCuentas(array $cuentas): array
{
    return app(PropertyController::class)->update(
        Request::create('/api/properties/'.test()->property->id, 'PATCH', [
            'settings' => ['bank_accounts' => $cuentas],
        ]),
        test()->property,
    )->getData(true);
}

/** Los datos reales de cabañas. */
function cuentaCompleta(array $overrides = []): array
{
    return array_replace([
        'bank' => 'BBVA',
        'holder' => 'Jatziry Sofía Salazar Salazar',
        'clabe' => '012164015648463025',
        'card' => '4152314577952941',
        'account' => '1564846302',
        'active' => true,
    ], $overrides);
}

// ------------------------------------------------------------ validación

it('guarda los tres números en su campo', function () {
    guardarCuentas([cuentaCompleta()]);

    $guardada = $this->property->refresh()->settings['bank_accounts'][0];

    expect($guardada['clabe'])->toBe('012164015648463025')
        ->and($guardada['card'])->toBe('4152314577952941')
        ->and($guardada['account'])->toBe('1564846302');
});

it('rechaza cada número por lo que ES', function (array $overrides, string $texto) {
    expect(fn () => guardarCuentas([cuentaCompleta($overrides)]))
        ->toThrow(function (ValidationException $e) use ($texto) {
            expect(implode(' ', \Illuminate\Support\Arr::flatten($e->errors())))->toContain($texto);
        });
})->with([
    'CLABE con un dígito cambiado' => [['clabe' => '012164015648463024'], 'dígito verificador'],
    'tarjeta que no pasa la verificación' => [['card' => '4152313477952941'], 'no pasa la verificación'],
    'cuenta de 12 dígitos' => [['account' => '156484630212'], '10 u 11 dígitos'],
]);

it('no guarda una cuenta sin ningún número', function () {
    expect(fn () => guardarCuentas([[
        'bank' => 'BBVA',
        'holder' => 'Jatziry Sofía Salazar Salazar',
        'active' => true,
    ]]))->toThrow(function (ValidationException $e) {
        expect(implode(' ', \Illuminate\Support\Arr::flatten($e->errors())))
            ->toContain('al menos un número');
    });
});

it('acepta un registro viejo tal cual y lo deja separado', function () {
    // Lo que hoy está guardado en producción: la tarjeta en la llave `clabe`.
    guardarCuentas([[
        'bank' => 'BBVA Bancomer',
        'holder' => 'Jatziry Sofía Salazar Salazar',
        'clabe' => '4152314577952941',
        'active' => true,
    ]]);

    $guardada = $this->property->refresh()->settings['bank_accounts'][0];

    expect($guardada['card'])->toBe('4152314577952941')
        ->and($guardada['clabe'])->toBe('');
});

it('guardar cuentas no borra el resto de los ajustes', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'transfer_hours_enabled' => true,
        'transfer_hours_open' => '09:00',
    ])]);

    guardarCuentas([cuentaCompleta()]);

    expect($this->property->refresh()->settings['transfer_hours_open'])->toBe('09:00');
});

// ------------------------------------------------- lo que ve el huésped

it('al huésped le da la CLABE y la tarjeta como alternativa, nunca la cuenta', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'bank_accounts' => [cuentaCompleta()],
    ])]);

    $cuenta = app(ReservationPolicy::class)->guestTransferAccounts(true)->first();

    expect($cuenta['cuenta'])->toBe('012164015648463025')
        ->and($cuenta['tipo'])->toBe('CLABE interbancaria')
        ->and($cuenta['alternativa']['cuenta'])->toBe('4152314577952941')
        ->and($cuenta['alternativa']['tipo'])->toBe('Tarjeta de débito')
        // El número interno no viaja en ningún campo del payload.
        ->and(json_encode($cuenta))->not->toContain('"1564846302"');
});

it('con la transferencia apagada no da ninguna cuenta', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'bank_accounts' => [cuentaCompleta()],
    ])]);

    expect(app(ReservationPolicy::class)->guestTransferAccounts(false))->toBeEmpty();
});

// --------------------------------------------- el comprobante y su destino

it('un comprobante hecho a la tarjeta ya no se marca como cuenta ajena', function (string $last4, bool $coincide) {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'bank_accounts' => [cuentaCompleta()],
    ])]);

    $veredicto = app(ReceiptCheck::class)->evaluate([
        'kind' => 'transfer_receipt',
        'amount' => 2250,
        'destination_account_last4' => $last4,
        'tracking_key' => 'MBAN0100260917000'.$last4,
    ]);

    $avisos = implode(' ', $veredicto['warnings']);

    expect(str_contains($avisos, 'no coincide'))->toBe(! $coincide);
})->with([
    'a la tarjeta' => ['2941', true],
    'a la CLABE' => ['3025', true],
    'al número de cuenta' => ['6302', true],
    'a una cuenta ajena' => ['9999', false],
]);
