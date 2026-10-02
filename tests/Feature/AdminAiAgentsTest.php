<?php

use App\Models\Central\AddonService;
use App\Models\Central\PlatformAiProvider;
use App\Models\Central\TenantAddonService;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Agent\PlatformAgentGate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function aiAdmin(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

function aiTenant(string $id, string $plan = 'esencial'): Tenant
{
    return Tenant::withoutEvents(fn () => Tenant::create(['id' => $id, 'name' => ucfirst($id), 'plan' => $plan]));
}

beforeEach(function () {
    TenantAddonService::query()->delete();
    AddonService::query()->update(['active' => false]);
});

it('un hotel con el asistente contratado aparece con IA y con la cuota del servicio', function () {
    // Plan sin IA + servicio adicional que trae el módulo y 300 respuestas.
    config()->set('plans.esencial.ai', ['enabled' => false, 'monthly_replies' => 0]);
    config()->set('plans.esencial.modules', ['pos']);
    AddonService::create([
        'key' => 'svc-asistente', 'name' => 'Asistente', 'price_monthly' => 500, 'activation_fee' => 0,
        'modules' => ['agente-ia'], 'ai_monthly_replies' => 300, 'active' => true, 'sort_order' => 1,
    ]);
    $tenant = aiTenant('hotelia');
    TenantAddonService::create(['tenant_id' => 'hotelia', 'addon_service_key' => 'svc-asistente']);

    expect(PlatformAgentGate::defaultLimit($tenant))->toBe(300);

    $this->actingAs(aiAdmin())
        ->get(route('admin.ai'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/AiAgents')
            ->where('tenants.0.ai_available', true)
            ->where('tenants.0.ai_from_addon', true)
            ->where('tenants.0.default_limit', 300));
});

it('un hotel sin IA en plan ni servicio aparece sin IA', function () {
    config()->set('plans.esencial.ai', ['enabled' => false, 'monthly_replies' => 0]);
    config()->set('plans.esencial.modules', ['pos']);
    aiTenant('hotelsinia');

    $this->actingAs(aiAdmin())
        ->get(route('admin.ai'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenants.0.ai_available', false)
            ->where('tenants.0.ai_from_addon', false));
});

it('el admin reordena la cadena automática de keys', function () {
    $a = PlatformAiProvider::create(['provider' => 'openai', 'model' => 'gpt-x', 'api_key' => 'sk-a', 'active' => true, 'sort_order' => 1]);
    $b = PlatformAiProvider::create(['provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'sk-b', 'active' => true, 'sort_order' => 2]);

    $this->actingAs(aiAdmin())
        ->postJson(route('admin.ai.providers.reorder'), ['ids' => [$b->id, $a->id]])
        ->assertOk();

    expect($b->fresh()->sort_order)->toBe(1)
        ->and($a->fresh()->sort_order)->toBe(2);

    $this->actingAs(aiAdmin())
        ->postJson(route('admin.ai.providers.reorder'), ['ids' => [999999]])
        ->assertUnprocessable();
});
