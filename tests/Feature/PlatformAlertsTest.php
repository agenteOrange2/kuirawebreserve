<?php

use App\Models\Central\ModuleActivationRequest;
use App\Models\Central\PlatformAlert;
use App\Models\Central\PlatformAiProvider;
use App\Models\Central\TenantAgentSetting;
use App\Models\Central\TenantAiUsage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\PlatformAlertScanner;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Avisos del panel de plataforma (/admin/notificaciones): el escáner abre,
 * escala, conserva y resuelve; los estados (leído, pospuesto, descartado)
 * sobreviven entre revisiones y lo que empeora vuelve a abrirse.
 */
function alertsAdmin(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

/** Hotel en la central sin base propia: su revisión interna falla a propósito. */
function alertsTenant(string $id, array $attrs = []): Tenant
{
    return Tenant::withoutEvents(fn () => Tenant::create(['id' => $id, 'name' => ucfirst($id), 'plan' => 'esencial'] + $attrs));
}

function scanAlerts(): array
{
    return app(PlatformAlertScanner::class)->scan();
}

beforeEach(function () {
    // Con llaves de IA activas, para que ese aviso no estorbe en cada prueba.
    PlatformAiProvider::create(['provider' => 'openai', 'model' => 'x', 'api_key' => 'k', 'active' => true, 'sort_order' => 1]);
});

it('avisa al 80 % de la cuota de IA y lo escala a urgente al 100 %', function () {
    alertsTenant('hotelia');
    TenantAgentSetting::create(['tenant_id' => 'hotelia', 'enabled' => true, 'monthly_reply_limit' => 100]);
    $usage = TenantAiUsage::create(['tenant_id' => 'hotelia', 'date' => now()->toDateString(), 'replies' => 85, 'prompt_tokens' => 0, 'completion_tokens' => 0]);

    scanAlerts();
    $alert = PlatformAlert::query()->where('type', 'ai_quota')->firstOrFail();
    expect($alert->severity)->toBe('warning')
        ->and($alert->title)->toContain('85 %');

    // Ya lo descartaron... y luego se acaba la cuota: vuelve a abrirse.
    $alert->update(['dismissed_at' => now(), 'read_at' => now()]);
    $usage->update(['replies' => 100]);
    scanAlerts();

    $alert->refresh();
    expect($alert->severity)->toBe('danger')
        ->and($alert->dismissed_at)->toBeNull()
        ->and($alert->read_at)->toBeNull();
});

it('lo que deja de darse se resuelve solo, y si vuelve se abre de nuevo', function () {
    alertsTenant('hotelmod');
    ModuleActivationRequest::create(['tenant_id' => 'hotelmod', 'module' => 'pos']);

    scanAlerts();
    $alert = PlatformAlert::query()->where('key', 'module_request:hotelmod:pos')->firstOrFail();
    expect($alert->resolved_at)->toBeNull();

    ModuleActivationRequest::query()->delete();
    scanAlerts();
    expect($alert->refresh()->resolved_at)->not->toBeNull();

    ModuleActivationRequest::create(['tenant_id' => 'hotelmod', 'module' => 'pos']);
    $alert->update(['read_at' => now()]);
    scanAlerts();
    expect($alert->refresh()->resolved_at)->toBeNull()
        ->and($alert->read_at)->toBeNull();
});

it('una base que no responde avisa urgente sin dar por resueltos sus otros avisos', function () {
    alertsTenant('sinbase');
    PlatformAlert::create([
        'key' => 'no_owner:sinbase', 'type' => 'no_owner', 'severity' => 'danger', 'tenant_id' => 'sinbase',
        'title' => 'No tiene propietario', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    scanAlerts();

    expect(PlatformAlert::query()->where('key', 'tenant_unreachable:sinbase')->first()?->severity)->toBe('danger')
        // No se sabe si ya tiene dueño: el aviso se conserva.
        ->and(PlatformAlert::query()->where('key', 'no_owner:sinbase')->first()->resolved_at)->toBeNull()
        ->and(tenancy()->initialized)->toBeFalse();
});

it('sin llaves de IA activas avisa urgente', function () {
    PlatformAiProvider::query()->update(['active' => false]);

    scanAlerts();

    expect(PlatformAlert::query()->where('key', 'ai_providers:none')->value('severity'))->toBe('danger');
});

it('la página lista los abiertos y las acciones cambian su estado', function () {
    $admin = alertsAdmin();
    $alert = PlatformAlert::create([
        'key' => 'new_tenant:x', 'type' => 'new_tenant', 'severity' => 'info',
        'title' => 'Hotel nuevo en la plataforma', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $this->actingAs($admin)->get(route('admin.alerts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/alerts/Index')
            ->where('counts.open', 1)
            ->where('counts.unread', 1)
            ->where('alerts.data.0.title', 'Hotel nuevo en la plataforma'));

    $this->actingAs($admin)->patch(route('admin.alerts.update'), ['ids' => [$alert->id], 'action' => 'snooze', 'hours' => 4])
        ->assertSessionHasNoErrors();
    expect($alert->refresh()->snoozed_until)->not->toBeNull()
        ->and(PlatformAlert::query()->open()->count())->toBe(0)
        ->and(PlatformAlert::query()->snoozed()->count())->toBe(1);

    $this->actingAs($admin)->patch(route('admin.alerts.update'), ['ids' => [$alert->id], 'action' => 'dismiss']);
    expect($alert->refresh()->dismissed_by)->toBe($admin->id);

    $this->actingAs($admin)->patch(route('admin.alerts.update'), ['ids' => [$alert->id], 'action' => 'restore']);
    expect(PlatformAlert::query()->open()->count())->toBe(1);
});

it('marcar avisos no ensucia la bitácora del admin', function () {
    $admin = alertsAdmin();
    $alert = PlatformAlert::create([
        'key' => 'k', 'type' => 'new_tenant', 'severity' => 'info',
        'title' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $this->actingAs($admin)->patch(route('admin.alerts.update'), ['ids' => [$alert->id], 'action' => 'read']);
    $this->actingAs($admin)->post(route('admin.alerts.read-all'));

    expect(\App\Models\Central\AdminActivity::query()->count())->toBe(0);
});
