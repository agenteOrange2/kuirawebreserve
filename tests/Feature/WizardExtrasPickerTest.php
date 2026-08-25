<?php

use App\Http\Controllers\Tenant\WizardSettingsPageController;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * El selector de extras del wizard con un inventario de verdad: la pantalla
 * solo debe cargar lo YA elegido y buscar el resto contra el servidor.
 *
 * Caso real (motellacupula, 2026-08-20): 69 productos activos y 4 marcados;
 * la pantalla pintaba los 69 para administrar 4, y con 200 se volvía
 * inservible.
 */
function bindPickerTenant(): Tenant
{
    $tenant = new Tenant;
    $tenant->id = 'hotel-picker-test';
    $tenant->plan = 'basic';

    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
    app()->instance(Tenant::class, $tenant);

    return $tenant;
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
    bindPickerTenant();

    // Un inventario grande con unos pocos elegidos.
    Product::factory()->count(60)->create([
        'property_id' => $this->property->id,
        'active' => true,
        'available_in_wizard' => false,
    ]);

    $this->elegido = Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Botella de vino tinto',
        'category' => 'Bebidas',
        'active' => true,
        'available_in_wizard' => true,
    ]);
});

it('la búsqueda encuentra por nombre y por categoría', function () {
    $buscar = fn (string $q) => json_decode(
        app(WizardSettingsPageController::class)
            ->searchProducts(Request::create('/x', 'GET', ['q' => $q]))
            ->getContent(),
        true,
    );

    $porNombre = $buscar('vino tinto');
    $porCategoria = $buscar('Bebidas');

    expect($porNombre['products'])->toHaveCount(1)
        ->and($porNombre['products'][0]['name'])->toBe('Botella de vino tinto')
        ->and($porNombre['products'][0]['available_in_wizard'])->toBeTrue()
        ->and(count($porCategoria['products']))->toBeGreaterThanOrEqual(1);
});

it('la búsqueda se corta y lo avisa en vez de recortar en silencio', function () {
    $todos = json_decode(
        app(WizardSettingsPageController::class)
            ->searchProducts(Request::create('/x', 'GET', ['q' => '']))
            ->getContent(),
        true,
    );

    // 61 productos activos, tope de 40: debe avisar que hay más.
    expect($todos['products'])->toHaveCount(40)
        ->and($todos['truncated'])->toBeTrue();
});

it('sin el módulo POS el buscador responde 403', function () {
    \App\Models\Central\TenantModule::create([
        'tenant_id' => 'hotel-picker-test',
        'module' => 'pos',
        'enabled' => false,
    ]);

    expect(fn () => app(WizardSettingsPageController::class)
        ->searchProducts(Request::create('/x', 'GET')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
