<?php

use App\Http\Controllers\Tenant\LoginBackgroundController;
use App\Http\Controllers\Tenant\PropertyController;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\TenantLoginBrand;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// En el dominio de cada hotel el login lleva SU marca (logo, nombre, colores
// del panel y textos/foto propios) y la plataforma firma abajo. Fuera de un
// hotel, o con la marca apagada, se ve el login de la plataforma.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Storage::fake('public');

    $this->property = Property::factory()->create([
        'name' => 'Cabañas Real de la Sierra',
        'settings' => [
            'panel_primary' => '#6d2c03',
            'panel_menu_from' => '#000000',
            'panel_menu_to' => '#502811',
        ],
    ]);
});

afterEach(function () {
    tenancy()->initialized = false;
    tenancy()->tenant = null;
});

function comoDominioDelHotel(): void
{
    tenancy()->tenant = new Tenant(['id' => 'cabanas', 'name' => 'Cabañas']);
    tenancy()->initialized = true;
}

function guardarAjustesLogin(array $settings): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/properties/'.test()->property->id, 'PATCH', ['settings' => $settings]);

    return app(PropertyController::class)->update($request, test()->property);
}

test('en el dominio central no hay marca de hotel', function () {
    expect(TenantLoginBrand::resolve())->toBeNull();
});

test('sin personalizar, el hotel ya sale con su nombre y sus colores', function () {
    comoDominioDelHotel();

    $brand = TenantLoginBrand::resolve();

    expect($brand['name'])->toBe('Cabañas Real de la Sierra')
        ->and($brand['logo_url'])->toBeNull()
        ->and($brand['title'])->toBeNull()
        ->and($brand['background_url'])->toBeNull()
        ->and($brand['colors'])->toBe([
            'primary' => '#6d2c03',
            'menu_from' => '#000000',
            'menu_to' => '#502811',
        ]);
});

test('los textos y la foto del hotel llegan al login', function () {
    comoDominioDelHotel();

    guardarAjustesLogin([
        'login_hint' => 'Entra con tu correo de recepción',
        'login_title' => "Bienvenido a\nlas cabañas",
        'login_subtitle' => '   ',
    ]);

    $request = Request::create('/api/login-background', 'POST', [], [], [
        'background' => UploadedFile::fake()->image('bosque.jpg', 1600, 900),
    ]);
    app()->instance('request', $request);
    app(LoginBackgroundController::class)->store($request);

    $brand = TenantLoginBrand::resolve();

    expect($brand['hint'])->toBe('Entra con tu correo de recepción')
        ->and($brand['title'])->toBe("Bienvenido a\nlas cabañas")
        // En blanco = cae en el texto de la plataforma.
        ->and($brand['subtitle'])->toBeNull()
        ->and($brand['background_url'])->toStartWith('/fotos/fondo-login?v=');

    app(LoginBackgroundController::class)->destroy();

    expect(TenantLoginBrand::resolve()['background_url'])->toBeNull();
});

test('el hotel puede apagar su marca y volver al login de la plataforma', function () {
    comoDominioDelHotel();

    guardarAjustesLogin(['login_brand_enabled' => false]);

    expect(TenantLoginBrand::resolve())->toBeNull();

    guardarAjustesLogin(['login_brand_enabled' => true]);

    expect(TenantLoginBrand::resolve())->not->toBeNull();
});

test('los textos del login tienen tope', function () {
    guardarAjustesLogin(['login_title' => str_repeat('a', 121)]);
})->throws(\Illuminate\Validation\ValidationException::class);
