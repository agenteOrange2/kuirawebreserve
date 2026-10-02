<?php

use App\Models\Central\AddonService;
use App\Models\Central\TenantAddonService;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * /admin/servicios: el ingreso y las contrataciones solo cuentan servicios
 * en catálogo (igual que Tenant::addonServices()), un servicio fuera del
 * catálogo no se contrata, y retirar uno tira los que lo amplían.
 */
function servicesAdmin(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

function servicesTenant(string $id): Tenant
{
    return Tenant::withoutEvents(fn () => Tenant::create(['id' => $id, 'name' => ucfirst($id), 'plan' => 'esencial']));
}

beforeEach(function () {
    TenantAddonService::query()->delete();
    AddonService::query()->update(['active' => false]);

    AddonService::create(['key' => 'svc-base', 'name' => 'Base', 'price_monthly' => 600, 'activation_fee' => 800, 'modules' => [], 'active' => true, 'sort_order' => 1]);
    AddonService::create(['key' => 'svc-amplia', 'name' => 'Amplía', 'price_monthly' => 500, 'activation_fee' => 800, 'modules' => [], 'requires' => 'svc-base', 'active' => true, 'sort_order' => 2]);
    AddonService::create(['key' => 'svc-pausado', 'name' => 'Pausado', 'price_monthly' => 900, 'activation_fee' => 0, 'modules' => [], 'active' => false, 'sort_order' => 3]);
});

it('el ingreso mensual no cuenta servicios fuera del catálogo', function () {
    servicesTenant('hotela');
    servicesTenant('hotelb');
    TenantAddonService::create(['tenant_id' => 'hotela', 'addon_service_key' => 'svc-base']);
    TenantAddonService::create(['tenant_id' => 'hotelb', 'addon_service_key' => 'svc-base']);
    TenantAddonService::create(['tenant_id' => 'hotelb', 'addon_service_key' => 'svc-pausado']);

    $this->actingAs(servicesAdmin())
        ->get(route('admin.services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/services/Index')
            ->where('stats.mrr_addons', 1200)
            ->where('stats.contracts', 2)
            ->where('stats.tenants_with_addons', 2)
            ->where('tenants.0.suspended', false)
            ->has('services.0.last_change'));
});

it('no deja contratar un servicio fuera del catálogo', function () {
    servicesTenant('hotela');

    $this->actingAs(servicesAdmin())
        ->patch(route('admin.tenants.addon-services', ['tenant' => 'hotela', 'addonService' => 'svc-pausado']), ['contracted' => true])
        ->assertSessionHasErrors('service');

    expect(TenantAddonService::query()->count())->toBe(0);
});

it('pide el servicio que amplía antes de contratarlo', function () {
    servicesTenant('hotela');
    $admin = servicesAdmin();

    $this->actingAs($admin)
        ->patch(route('admin.tenants.addon-services', ['tenant' => 'hotela', 'addonService' => 'svc-amplia']), ['contracted' => true])
        ->assertSessionHasErrors('service');

    $this->actingAs($admin)
        ->patch(route('admin.tenants.addon-services', ['tenant' => 'hotela', 'addonService' => 'svc-base']), ['contracted' => true])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)
        ->patch(route('admin.tenants.addon-services', ['tenant' => 'hotela', 'addonService' => 'svc-amplia']), ['contracted' => true])
        ->assertSessionHasNoErrors();

    expect(TenantAddonService::query()->where('tenant_id', 'hotela')->count())->toBe(2);
});

it('retirar un servicio tira los que lo amplían', function () {
    servicesTenant('hotela');
    TenantAddonService::create(['tenant_id' => 'hotela', 'addon_service_key' => 'svc-base']);
    TenantAddonService::create(['tenant_id' => 'hotela', 'addon_service_key' => 'svc-amplia']);

    $this->actingAs(servicesAdmin())
        ->patch(route('admin.tenants.addon-services', ['tenant' => 'hotela', 'addonService' => 'svc-base']), ['contracted' => false])
        ->assertSessionHasNoErrors();

    expect(TenantAddonService::query()->where('tenant_id', 'hotela')->count())->toBe(0);
});
