<?php

use App\Models\Central\AdminActivity;
use App\Models\Central\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Pago por adelantado en /admin/planes: cada plan se puede vender por
 * trimestre, semestre, año o más con su descuento por tiempo, y el
 * interruptor de la tarjeta prende o apaga la venta sin reenviar el plan.
 */
function billingAdmin(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

function billingPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Profesional',
        'price_monthly' => 1000,
        'activation_fee' => 0,
        'modules' => [],
        'active' => true,
        'public' => true,
    ], $overrides);
}

it('guarda los periodos limpios y calcula el precio con descuento', function () {
    $plan = Plan::create(['key' => 'pro-periodos', 'label' => 'Pro', 'price_monthly' => 1000]);

    $this->actingAs(billingAdmin())
        ->patch(route('admin.plans.update', $plan->key), billingPayload([
            'billing_periods' => [
                ['months' => 12, 'discount' => 20],
                ['months' => 6, 'discount' => 10],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $plan->refresh();
    expect($plan->billing_periods)->toBe([
        ['months' => 6, 'discount' => 10],
        ['months' => 12, 'discount' => 20],
    ]);

    $options = collect($plan->billingOptions())->keyBy('months');
    expect($options[1]['total'])->toBe(1000)
        ->and($options[6]['total'])->toBe(5400)
        ->and($options[6]['monthly'])->toBe(900)
        ->and($options[12]['total'])->toBe(9600)
        ->and($options[12]['saving'])->toBe(2400)
        ->and($options[12]['label'])->toBe('Anual');
});

it('rechaza periodos inventados, repetidos o con descuento fuera de rango', function () {
    $plan = Plan::create(['key' => 'pro-malo', 'label' => 'Pro', 'price_monthly' => 1000]);
    $admin = billingAdmin();

    $this->actingAs($admin)
        ->patch(route('admin.plans.update', $plan->key), billingPayload([
            'billing_periods' => [['months' => 5, 'discount' => 10]],
        ]))
        ->assertSessionHasErrors('billing_periods.0.months');

    $this->actingAs($admin)
        ->patch(route('admin.plans.update', $plan->key), billingPayload([
            'billing_periods' => [['months' => 12, 'discount' => 10], ['months' => 12, 'discount' => 5]],
        ]))
        ->assertSessionHasErrors('billing_periods.1.months');

    $this->actingAs($admin)
        ->patch(route('admin.plans.update', $plan->key), billingPayload([
            'billing_periods' => [['months' => 12, 'discount' => 95]],
        ]))
        ->assertSessionHasErrors('billing_periods.0.discount');

    expect($plan->fresh()->billing_periods)->toBeNull();
});

it('sin periodos el plan queda solo mensual', function () {
    $plan = Plan::create([
        'key' => 'pro-solo-mes', 'label' => 'Pro', 'price_monthly' => 800,
        'billing_periods' => [['months' => 12, 'discount' => 10]],
    ]);

    $this->actingAs(billingAdmin())
        ->patch(route('admin.plans.update', $plan->key), billingPayload(['billing_periods' => []]))
        ->assertSessionHasNoErrors();

    expect($plan->fresh()->billing_periods)->toBeNull()
        ->and($plan->fresh()->billingOptions())->toHaveCount(1);
});

it('deja en la bitácora el cambio de periodos en palabras', function () {
    $plan = Plan::create(['key' => 'pro-bitacora', 'label' => 'Pro', 'price_monthly' => 1000]);

    $this->actingAs(billingAdmin())
        ->patch(route('admin.plans.update', $plan->key), billingPayload([
            'billing_periods' => [['months' => 12, 'discount' => 15]],
        ]));

    $row = AdminActivity::query()->where('action', 'admin.plans.update')->sole();
    expect($row->properties['changes']['billing_periods'])->toBe(['Solo mensual', 'Anual −15 %']);
});

it('el interruptor de la tarjeta funciona aunque el plan traiga una cuota de IA heredada en 0', function () {
    // Con la validación completa, ai_monthly_replies = 0 (min:1) tumbaba el
    // guardado y el switch regresaba solo, sin aviso.
    $plan = Plan::create([
        'key' => 'legacy-ia', 'label' => 'Legacy', 'price_monthly' => 0,
        'modules' => ['agente-ia'], 'ai_monthly_replies' => 0, 'active' => true,
    ]);

    $this->actingAs(billingAdmin())
        ->patch(route('admin.plans.active', $plan->key), ['active' => false])
        ->assertSessionHasNoErrors();

    $plan->refresh();
    expect($plan->active)->toBeFalse()
        ->and($plan->ai_monthly_replies)->toBe(0)
        ->and(AdminActivity::query()->where('action', 'admin.plans.active')->sole()->properties['changes']['active'])
        ->toBe([1, 0]);
});

it('la pantalla y la página de inicio reciben los periodos', function () {
    Plan::create([
        'key' => 'pro-landing', 'label' => 'Pro', 'price_monthly' => 1000, 'active' => true, 'public' => true,
        'billing_periods' => [['months' => 6, 'discount' => 0], ['months' => 12, 'discount' => 20]],
    ]);

    $this->actingAs(billingAdmin())->get(route('admin.plans'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/plans/Index')
            ->has('periods', count(Plan::PERIODS))
            ->where('plans', fn ($plans) => collect($plans)->firstWhere('key', 'pro-landing')['billing_options'][2]['monthly'] === 800));

    // En la página de inicio solo se anuncian los que traen descuento.
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('plans', fn ($plans) => ($plan = collect($plans)->firstWhere('key', 'pro-landing'))
            && count($plan['billing_options']) === 1
            && $plan['billing_options'][0]['months'] === 12));
});
