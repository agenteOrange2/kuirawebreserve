<?php

use App\Models\Central\AdminActivity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Bitácora del panel de plataforma: toda acción que cambia algo bajo
 * /admin queda con quién, sobre qué y qué cambió; y la ficha del usuario
 * (/admin/usuarios/{id}) la enseña paginada.
 */
function activityAdmin(array $attrs = []): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create($attrs);
    $user->assignRole('platform-admin');

    return $user;
}

/** Hotel en la base central sin crearle base propia (sin eventos de tenancy). */
function activityTenant(string $id = 'hotelbitacora'): Tenant
{
    return Tenant::withoutEvents(fn () => Tenant::create(['id' => $id, 'name' => 'Hotel Bitácora', 'plan' => 'esencial']));
}

it('registra la edición de un usuario con lo que cambió y sin la contraseña', function () {
    $admin = activityAdmin();
    $other = activityAdmin(['name' => 'Ana López']);

    $this->actingAs($admin)
        ->patchJson(route('admin.users.update', $other), [
            'name' => 'Ana María López',
            'password' => 'secreta-123',
        ])->assertOk();

    $row = AdminActivity::sole();
    expect($row->user_id)->toBe($admin->id)
        ->and($row->action)->toBe('admin.users.update')
        ->and($row->subject_type)->toBe('user')
        ->and($row->subject_label)->toBe('Ana López')
        ->and($row->properties['changes']['name'])->toBe(['Ana López', 'Ana María López'])
        ->and($row->properties['input']['password'])->toBe('(oculto)')
        ->and(json_encode($row->properties))->not->toContain('secreta-123');
});

it('no registra lo que no se guardó', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)
        ->postJson(route('admin.users.store'), ['name' => ''])
        ->assertStatus(422);

    $this->actingAs($admin)
        ->deleteJson(route('admin.users.destroy', $admin))
        ->assertStatus(422);

    expect(AdminActivity::count())->toBe(0);
});

it('un borrado conserva el nombre de lo que se borró', function () {
    $admin = activityAdmin();
    $gone = User::factory()->create(['name' => 'Cuenta Vieja']);

    $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $gone))->assertNoContent();

    expect(AdminActivity::sole()->subject_label)->toBe('Cuenta Vieja');
});

it('suspender un hotel deja su nombre y en qué quedó', function () {
    $admin = activityAdmin();
    $tenant = activityTenant();

    $this->actingAs($admin)->patch(route('admin.tenants.suspend', $tenant))->assertRedirect();

    $row = AdminActivity::sole();
    expect($row->tenant_id)->toBe('hotelbitacora')
        ->and($row->subject_type)->toBe('tenant')
        ->and($row->subject_label)->toBe('Hotel Bitácora')
        ->and($row->properties['suspended'])->toBeTrue();
});

it('las consultas no se registran', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->get(route('admin.users'))->assertOk();

    expect(AdminActivity::count())->toBe(0);
});

it('el inicio de sesión central queda en la bitácora', function () {
    $admin = activityAdmin();

    event(new Login('web', $admin, false));

    expect(AdminActivity::sole()->action)->toBe('auth.login');
});

it('la ficha del usuario enseña su historial filtrable', function () {
    $admin = activityAdmin();
    $tenant = activityTenant();

    $this->actingAs($admin)->patch(route('admin.tenants.suspend', $tenant));
    $this->actingAs($admin)->patchJson(route('admin.users.update', $admin), ['phone' => '6141234567']);

    $this->actingAs($admin)->get(route('admin.users.show', $admin))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/Show')
            ->where('user.id', $admin->id)
            ->has('history.data', 2)
            ->where('history.data.0.label', 'Editó el usuario')
            ->where('history.data.1.label', 'Suspendió el hotel')
            ->where('history.data.1.tenant.name', 'Hotel Bitácora')
            ->where('stats.actions_30d', 2)
            ->where('stats.tenants_30d', 1)
            ->has('tenants', 1)
        );

    $this->actingAs($admin)->get(route('admin.users.show', [$admin, 'category' => 'tenants']))
        ->assertInertia(fn (Assert $page) => $page->has('history.data', 1));

    $this->actingAs($admin)->get(route('admin.users'))
        ->assertInertia(fn (Assert $page) => $page->where('users.0.actions_30d', 2));
});

it('la tarjeta del plan dice quién lo editó por última vez', function () {
    $admin = activityAdmin(['name' => 'Marco Admin']);
    $plan = App\Models\Central\Plan::query()->ordered()->firstOrFail();

    $this->actingAs($admin)->patch(route('admin.plans.update', $plan->key), [
        'label' => $plan->label.' Plus',
        'price_monthly' => 999,
        'modules' => $plan->modules ?? [],
    ])->assertRedirect();

    $this->actingAs($admin)->get(route('admin.plans'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/plans/Index')
            ->where('plans.0.last_change.by', 'Marco Admin')
            ->where('plans.1.last_change', null)
        );

    expect(AdminActivity::sole()->properties['changes']['label'])->toBe([$plan->label, $plan->label.' Plus']);
});
