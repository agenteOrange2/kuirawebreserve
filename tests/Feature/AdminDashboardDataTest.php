<?php

use App\Models\Central\TenantAiUsage;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Datos del dashboard de plataforma: el ingreso solo cuenta hoteles
 * activos, la dona no trae planes vacíos y la gráfica de actividad trae
 * todos los días (los de cero también) con su desglose por hotel.
 */
function dashboardTenant(string $id, string $plan, bool $suspended = false): Tenant
{
    return Tenant::withoutEvents(fn () => Tenant::create([
        'id' => $id, 'name' => ucfirst($id), 'plan' => $plan,
        'suspended_at' => $suspended ? now() : null,
    ]));
}

it('arma ingreso por plan, actividad diaria y crecimiento', function () {
    Role::findOrCreate('platform-admin');
    $admin = User::factory()->create();
    $admin->assignRole('platform-admin');

    $planKey = array_key_first(config('plans'));
    $price = (int) (config("plans.{$planKey}.price_monthly") ?? 0);
    dashboardTenant('activo', $planKey);
    dashboardTenant('pausado', $planKey, suspended: true);

    TenantAiUsage::create(['tenant_id' => 'activo', 'date' => now()->toDateString(), 'replies' => 7, 'prompt_tokens' => 0, 'completion_tokens' => 0]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Dashboard')
            ->where('stats.active', 1)
            ->where('stats.suspended', 1)
            // El suspendido no paga.
            ->where('stats.mrr', $price)
            ->has('plans', 1)
            ->where('plans.0.count', 2)
            ->where('plans.0.mrr', $price)
            ->has('activity', 30)
            ->where('activity.29.replies', 7)
            ->where('activity.29.by_tenant.0.name', 'Activo')
            ->where('activity.0.replies', 0)
            ->has('growth', 6)
            ->where('growth.5.tenants', 2));
});
