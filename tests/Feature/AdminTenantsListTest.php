<?php

use App\Models\Central\ModuleActivationRequest;
use App\Models\Central\TenantModule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * /admin/tenants: un hotel con la base rota no tumba el listado, suspender
 * regresa a donde estabas, y eliminar pide el subdominio escrito y limpia
 * lo central que no tiene llave foránea (para que un hotel nuevo con el
 * mismo subdominio no lo herede).
 */
function tenantsAdmin(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

/** Hotel en la base central sin base propia (sin eventos de tenancy). */
function listTenant(string $id): Tenant
{
    return Tenant::withoutEvents(fn () => Tenant::create(['id' => $id, 'name' => ucfirst($id), 'plan' => 'esencial']));
}

it('un hotel con la base sin respuesta no tumba el listado', function () {
    listTenant('sinbase');

    $this->actingAs(tenantsAdmin())
        ->get(route('admin.tenants.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/tenants/Index')
            ->where('tenants.0.id', 'sinbase')
            ->where('tenants.0.reachable', false)
            ->where('tenants.0.users', null));
});

it('suspender regresa a la página de donde se pidió', function () {
    $tenant = listTenant('hotelficha');

    $this->actingAs(tenantsAdmin())
        ->from(route('admin.tenants.show', $tenant))
        ->patch(route('admin.tenants.suspend', $tenant))
        ->assertRedirect(route('admin.tenants.show', $tenant));

    expect($tenant->fresh()->isSuspended())->toBeTrue();
});

it('eliminar exige teclear el subdominio', function () {
    $tenant = listTenant('hotelborrar');

    $this->actingAs(tenantsAdmin())
        ->delete(route('admin.tenants.destroy', $tenant), ['confirm' => 'otro'])
        ->assertSessionHasErrors('confirm');

    expect(Tenant::find('hotelborrar'))->not->toBeNull();
});

it('eliminar limpia lo central que colgaba del hotel', function () {
    $tenant = listTenant('hotelborrar');
    TenantModule::create(['tenant_id' => 'hotelborrar', 'module' => 'pos', 'enabled' => true]);
    ModuleActivationRequest::create(['tenant_id' => 'hotelborrar', 'module' => 'pos']);
    TenantModule::create(['tenant_id' => 'vecino', 'module' => 'pos', 'enabled' => true]);

    Tenant::withoutEvents(fn () => $this->actingAs(tenantsAdmin())
        ->delete(route('admin.tenants.destroy', $tenant), ['confirm' => 'hotelborrar'])
        ->assertSessionHasNoErrors());

    expect(Tenant::find('hotelborrar'))->toBeNull()
        ->and(DB::table('tenant_modules')->where('tenant_id', 'hotelborrar')->count())->toBe(0)
        ->and(DB::table('module_activation_requests')->where('tenant_id', 'hotelborrar')->count())->toBe(0)
        // Lo de otros hoteles no se toca.
        ->and(DB::table('tenant_modules')->where('tenant_id', 'vecino')->count())->toBe(1);
});

it('un usuario mal capturado no deja la conexión del hotel abierta', function () {
    // run() de stancl no regresa a central si el callback lanza (aquí: el
    // hotel no tiene base). inTenant() lo cierra pase lo que pase.
    $tenant = listTenant('sinbaseusr');

    $this->actingAs(tenantsAdmin())
        ->postJson(route('admin.tenants.users.store', $tenant), [])
        ->assertStatus(500);

    expect(tenancy()->initialized)->toBeFalse();
});
