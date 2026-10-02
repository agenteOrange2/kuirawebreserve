<?php

use App\Models\Central\PaymentGatewayLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\PaymentMethodGate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function paymentsAdmin(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

function paymentsTenant(string $id): Tenant
{
    return Tenant::withoutEvents(fn () => Tenant::create(['id' => $id, 'name' => ucfirst($id), 'plan' => 'esencial']));
}

function gatewayFor(string $tenantId, string $provider, string $mode = 'live', array $extra = []): PaymentGatewayLink
{
    return PaymentGatewayLink::create([
        'tenant_id' => $tenantId,
        'provider' => $provider,
        'mode' => $mode,
        'public_key' => 'pk',
        'secret_key' => 'sk',
        'webhook_secret' => 'wh',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
    ] + $extra);
}

it('dice con qué pasarela cobra cada hotel y si está en modo prueba', function () {
    paymentsTenant('hotelprueba');
    gatewayFor('hotelprueba', 'stripe', 'test');

    $this->actingAs(paymentsAdmin())
        ->get(route('admin.payments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/payments/Index')
            ->where('tenants.0.charging.provider', 'stripe')
            ->where('tenants.0.charging.mode', 'test')
            ->where('tenants.0.gateways.0.in_use', true)
            ->where('tenants.0.gateways.0.stale', true)
            ->where('methods.1.method', 'stripe')
            ->where('methods.1.charging.0', 'Hotelprueba'));
});

it('marca la pasarela conectada que no puede cobrar porque el hotel apagó su método', function () {
    paymentsTenant('hotelapagado');
    gatewayFor('hotelapagado', 'stripe', 'live', ['last_event_at' => now()]);
    app(PaymentMethodGate::class)->set('hotelapagado', 'stripe', false);

    $this->actingAs(paymentsAdmin())
        ->get(route('admin.payments'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenants.0.charging', null)
            ->where('tenants.0.has_overrides', true)
            ->where('tenants.0.methods.stripe.own', false)
            ->where('tenants.0.gateways.0.blocked_by', 'tenant')
            ->where('tenants.0.gateways.0.stale', false));
});

it('lista y deja quitar solo las pasarelas de hoteles que ya no existen', function () {
    paymentsTenant('hotelvivo');
    $viva = gatewayFor('hotelvivo', 'stripe');
    $huerfana = gatewayFor('hotelborrado', 'mercadopago');
    $admin = paymentsAdmin();

    $this->actingAs($admin)
        ->get(route('admin.payments'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('orphans', 1)
            ->where('orphans.0.id', $huerfana->id));

    $this->actingAs($admin)
        ->deleteJson(route('admin.payments.gateways.destroy', $viva))
        ->assertStatus(422);

    $this->actingAs($admin)
        ->deleteJson(route('admin.payments.gateways.destroy', $huerfana))
        ->assertOk();

    expect(PaymentGatewayLink::query()->whereKey($huerfana->id)->exists())->toBeFalse()
        ->and(PaymentGatewayLink::query()->whereKey($viva->id)->exists())->toBeTrue();
});
