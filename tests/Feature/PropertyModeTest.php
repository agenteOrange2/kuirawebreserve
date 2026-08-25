<?php

use App\Http\Controllers\Tenant\PropertyController;
use App\Models\Property;
use App\Services\PropertyMode;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
});

function patchTenantSettings(array $settings): Property
{
    $request = Request::create('/api/properties/'.test()->property->id, 'PATCH', [
        'settings' => $settings,
    ]);

    app(PropertyController::class)->update($request, test()->property);

    return test()->property->fresh();
}

it('el modo default es hotel y no enciende el registro exprés', function () {
    Property::factory()->create();

    $mode = app(PropertyMode::class);

    expect($mode->mode())->toBe(PropertyMode::HOTEL)
        ->and($mode->isMotel())->toBeFalse()
        ->and($mode->expressCheckInEnabled())->toBeFalse();
});

it('un valor basura en settings cae de vuelta a hotel', function () {
    Property::factory()->create(['settings' => ['property_mode' => 'castillo']]);

    expect(app(PropertyMode::class)->mode())->toBe(PropertyMode::HOTEL);
});

it('modo motel (sembrado desde el admin) activa el registro exprés', function () {
    // El admin escribe el setting directo en la Property del tenant
    // (Admin\TenantController::store/update via $tenant->run()).
    Property::factory()->create(['settings' => ['property_mode' => 'motel']]);

    $mode = app(PropertyMode::class);

    expect($mode->isMotel())->toBeTrue()
        ->and($mode->hasMotel())->toBeTrue()
        ->and($mode->hasHotel())->toBeFalse()
        ->and($mode->expressCheckInEnabled())->toBeTrue();
});

it('modo ambos conserva hotel y además enciende el registro exprés', function () {
    Property::factory()->create(['settings' => ['property_mode' => 'both']]);

    $mode = app(PropertyMode::class);

    expect($mode->mode())->toBe(PropertyMode::BOTH)
        ->and($mode->isBoth())->toBeTrue()
        // "Ambos" no es motel puro ni hotel puro, pero tiene las dos.
        ->and($mode->isMotel())->toBeFalse()
        ->and($mode->isHotel())->toBeFalse()
        ->and($mode->hasMotel())->toBeTrue()
        ->and($mode->hasHotel())->toBeTrue()
        ->and($mode->expressCheckInEnabled())->toBeTrue();
});

it('las semillas del alta solo imponen caseta en motel puro', function () {
    // Motel arranca como caseta; "ambos" también vende noches a familias,
    // así que no puede nacer con solo adultos ni con el menú de motel.
    expect(PropertyMode::seedSettings(PropertyMode::HOTEL))->toBeNull()
        ->and(PropertyMode::seedSettings(PropertyMode::MOTEL))->toBe([
            'property_mode' => 'motel',
            'guest_policy' => 'adults_only',
            'menu_billing_mode' => 'motel',
        ])
        ->and(PropertyMode::seedSettings(PropertyMode::BOTH))->toBe([
            'property_mode' => 'both',
        ]);
});

it('el tenant NO puede cambiar el modo por su API: se descarta en silencio', function () {
    $this->property = Property::factory()->create(['settings' => ['property_mode' => 'motel']]);

    $property = patchTenantSettings(['property_mode' => 'hotel', 'currency' => 'USD']);

    // El modo es decisión de plataforma (/admin): el intento se ignora,
    // pero el resto del payload sí se guarda.
    expect($property->settings['property_mode'])->toBe('motel')
        ->and($property->settings['currency'])->toBe('USD');
});

it('guardar otros ajustes del tenant no pisa el modo', function () {
    $this->property = Property::factory()->create(['settings' => ['property_mode' => 'motel', 'walkin_charge' => 'checkin']]);

    $property = patchTenantSettings(['check_in_time' => '14:00']);

    expect($property->settings['property_mode'])->toBe('motel')
        ->and($property->settings['walkin_charge'])->toBe('checkin')
        ->and($property->settings['check_in_time'])->toBe('14:00');
});

it('el gate por modo deja pasar solo a las propiedades que corresponden', function () {
    Property::factory()->create(['settings' => ['property_mode' => 'motel']]);

    $passthrough = fn () => new \Illuminate\Http\Response('ok');
    $middleware = app(\App\Http\Middleware\EnsurePropertyMode::class);

    $allowed = $middleware->handle(Request::create('/vehiculos'), $passthrough, 'motel');

    expect($allowed->getContent())->toBe('ok');
});

it('el gate por modo bloquea con una página propia, no con la de módulos', function () {
    // "Ambos" tampoco entra a lo que es exclusivo de motel puro: la sección
    // de vehículos se decidió solo para moteles.
    Property::factory()->create(['settings' => ['property_mode' => 'both']]);

    $response = app(\App\Http\Middleware\EnsurePropertyMode::class)->handle(
        Request::create('/vehiculos'),
        fn () => new \Illuminate\Http\Response('ok'),
        'motel',
    );

    expect($response->getStatusCode())->toBe(403);
});

it('el gate por modo acepta varios modos y no se queda con el primero', function () {
    // Laravel parte los parámetros del middleware por coma: con una firma no
    // variádica, `mode:motel,both` se quedaría solo con "motel".
    Property::factory()->create(['settings' => ['property_mode' => 'both']]);

    $response = app(\App\Http\Middleware\EnsurePropertyMode::class)->handle(
        Request::create('/api/vehicles/lookup'),
        fn () => new \Illuminate\Http\Response('ok'),
        'motel',
        'both',
    );

    expect($response->getContent())->toBe('ok');
});
