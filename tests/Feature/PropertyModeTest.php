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
        ->and($mode->expressCheckInEnabled())->toBeTrue();
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
