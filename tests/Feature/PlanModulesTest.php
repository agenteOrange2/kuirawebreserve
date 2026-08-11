<?php

use App\Models\Central\ModuleActivationRequest;
use App\Models\Central\Plan;
use App\Models\Central\TenantModule;
use App\Models\Tenant;

/**
 * Tenant en memoria (sin save): crear uno de verdad dispara el pipeline de
 * tenancy (DB propia + migraciones), innecesario para probar la resolución.
 */
function moduleTenant(string $id, string $plan): Tenant
{
    $tenant = new Tenant;
    $tenant->id = $id;
    $tenant->plan = $plan;

    return $tenant;
}

it('backfill: los planes existentes reciben los módulos que ya otorgaban', function () {
    // Tras el escalonado comercial, los planes legacy conservan lo suyo Y
    // reciben los toggles que antes eran core (no se les quita nada).
    expect(Plan::find('basic')->modules)->toContain('pos', 'corte-caja', 'encuestas', 'cupones')
        ->and(Plan::find('basic')->modules)->not->toContain('agente-ia')
        ->and(Plan::find('pro')->modules)->toContain('pos', 'cobros', 'agente-ia', 'corte-caja', 'promos')
        // Los comerciales nuevos escalonan de verdad: Esencial solo con los
        // toggles que la comparativa 2026-08 le marca con "Sí",
        // Profesional sin los avanzados.
        ->and(Plan::find('esencial')->modules)->toBe(['tarifas-flexibles', 'corte-caja', 'bitacora'])
        ->and(Plan::find('profesional')->modules)->toContain('corte-caja', 'encuestas')
        ->and(Plan::find('profesional')->modules)->not->toContain('cupones', 'encuestas-avanzado')
        ->and(Plan::find('empresarial')->modules)->toContain('cupones', 'encuestas-avanzado', 'incidencias-avanzado', 'tablero-avanzado');
});

it('toConfigArray expone modules y deriva ai.enabled del módulo agente-ia', function () {
    $pro = Plan::find('pro')->toConfigArray();
    $basic = Plan::find('basic')->toConfigArray();

    expect($pro['modules'])->toContain('agente-ia')
        ->and($pro['ai']['enabled'])->toBeTrue()
        ->and($basic['modules'])->toContain('pos')
        ->and($basic['modules'])->not->toContain('agente-ia')
        ->and($basic['ai']['enabled'])->toBeFalse();
});

it('hasModule hereda del plan cuando no hay override', function () {
    $basico = moduleTenant('hotel-basico', 'basic');

    expect($basico->hasModule('pos'))->toBeTrue()
        ->and($basico->hasModule('cobros'))->toBeFalse()
        ->and($basico->hasModule('agente-ia'))->toBeFalse()
        ->and($basico->hasModule('modulo-inexistente'))->toBeFalse();

    $pro = moduleTenant('hotel-pro', 'pro');

    expect($pro->hasModule('cobros'))->toBeTrue()
        ->and($pro->hasModule('motor-web'))->toBeFalse();
});

it('el override del admin manda sobre el plan, en ambos sentidos', function () {
    TenantModule::create(['tenant_id' => 'hotel-basico', 'module' => 'agente-ia', 'enabled' => true]);
    TenantModule::create(['tenant_id' => 'hotel-basico', 'module' => 'pos', 'enabled' => false]);

    $tenant = moduleTenant('hotel-basico', 'basic');

    expect($tenant->hasModule('agente-ia'))->toBeTrue()
        ->and($tenant->hasModule('pos'))->toBeFalse()
        ->and($tenant->enabledModules())->toContain('agente-ia')
        ->and($tenant->enabledModules())->not->toContain('pos');

    // Otro hotel del mismo plan no se ve afectado.
    $otro = moduleTenant('hotel-vecino', 'basic');
    expect($otro->hasModule('pos'))->toBeTrue()
        ->and($otro->hasModule('agente-ia'))->toBeFalse();
});

it('enabledModules respeta el orden del catálogo', function () {
    $pro = moduleTenant('hotel-pro', 'pro');

    // Pro tiene todo el catálogo menos motor-web y los que hoy se venden
    // como servicio adicional (redes sociales, menú digital): el orden
    // esperado es el del catálogo mismo (config/modules.php), no el del plan.
    expect($pro->enabledModules())->toBe(array_values(array_diff(
        array_keys(config('modules')),
        ['motor-web', 'redes-sociales', 'menu-digital'],
    )));
});

it('las solicitudes de activación no se duplican', function () {
    ModuleActivationRequest::firstOrCreate(['tenant_id' => 'hotel-basico', 'module' => 'cobros']);
    ModuleActivationRequest::firstOrCreate(['tenant_id' => 'hotel-basico', 'module' => 'cobros']);

    expect(ModuleActivationRequest::query()->count())->toBe(1);
});

it('el escalonado comercial gatea de verdad por plan', function () {
    $esencial = moduleTenant('h-esencial', 'esencial');
    $profesional = moduleTenant('h-profesional', 'profesional');
    $empresarial = moduleTenant('h-empresarial', 'empresarial');

    // Esencial: el core más sus tres toggles de la comparativa 2026-08
    // (tarifas flexibles, corte de caja y bitácora); sin mensajería ni el
    // resto de lo que enciende el Profesional.
    expect($esencial->hasModule('corte-caja'))->toBeTrue()
        ->and($esencial->hasModule('tarifas-flexibles'))->toBeTrue()
        ->and($esencial->hasModule('bitacora'))->toBeTrue()
        ->and($esencial->hasModule('mensajeria'))->toBeFalse()
        ->and($esencial->hasModule('incidencias'))->toBeFalse()
        ->and($esencial->hasModule('encuestas'))->toBeFalse()
        ->and($esencial->hasModule('promos'))->toBeFalse()
        // Profesional: la operación recomendada, sin los avanzados.
        ->and($profesional->hasModule('corte-caja'))->toBeTrue()
        ->and($profesional->hasModule('encuestas'))->toBeTrue()
        ->and($profesional->hasModule('tarifas-flexibles'))->toBeTrue()
        ->and($profesional->hasModule('encuestas-avanzado'))->toBeFalse()
        ->and($profesional->hasModule('incidencias-avanzado'))->toBeFalse()
        ->and($profesional->hasModule('cupones'))->toBeFalse()
        ->and($profesional->hasModule('tablero-avanzado'))->toBeFalse()
        // Empresarial: todo lo del Profesional más los avanzados.
        ->and($empresarial->hasModule('corte-caja'))->toBeTrue()
        ->and($empresarial->hasModule('encuestas-avanzado'))->toBeTrue()
        ->and($empresarial->hasModule('incidencias-avanzado'))->toBeTrue()
        ->and($empresarial->hasModule('cupones'))->toBeTrue()
        ->and($empresarial->hasModule('tablero-avanzado'))->toBeTrue();
});
