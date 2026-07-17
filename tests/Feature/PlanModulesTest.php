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
    expect(Plan::find('basic')->modules)->toBe(['pos'])
        ->and(Plan::find('pro')->modules)->toBe(['pos', 'cobros', 'agente-ia']);
});

it('toConfigArray expone modules y deriva ai.enabled del módulo agente-ia', function () {
    $pro = Plan::find('pro')->toConfigArray();
    $basic = Plan::find('basic')->toConfigArray();

    expect($pro['modules'])->toContain('agente-ia')
        ->and($pro['ai']['enabled'])->toBeTrue()
        ->and($basic['modules'])->toBe(['pos'])
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
        ->and($tenant->enabledModules())->toBe(['agente-ia']);

    // Otro hotel del mismo plan no se ve afectado.
    $otro = moduleTenant('hotel-vecino', 'basic');
    expect($otro->hasModule('pos'))->toBeTrue()
        ->and($otro->hasModule('agente-ia'))->toBeFalse();
});

it('enabledModules respeta el orden del catálogo', function () {
    $pro = moduleTenant('hotel-pro', 'pro');

    expect($pro->enabledModules())->toBe(['pos', 'cobros', 'agente-ia', 'extras', 'experiencias', 'grupos']);
});

it('las solicitudes de activación no se duplican', function () {
    ModuleActivationRequest::firstOrCreate(['tenant_id' => 'hotel-basico', 'module' => 'cobros']);
    ModuleActivationRequest::firstOrCreate(['tenant_id' => 'hotel-basico', 'module' => 'cobros']);

    expect(ModuleActivationRequest::query()->count())->toBe(1);
});
