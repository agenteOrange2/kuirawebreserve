<?php

use App\Models\Central\Plan;
use App\Models\Central\PlanProspect;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function platformAdminForProspects(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

test('landing page muestra los planes y módulos activos', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('plans', 2)
            ->where('plans.0.key', 'basic')
            ->has('modules')
        );
});

test('un visitante puede solicitar información sobre un plan', function () {
    $this->post(route('prospects.store'), [
        'name' => 'Ana Torres',
        'hotel_name' => 'Hotel del Lago',
        'email' => 'ANA@HOTEL.test',
        'phone' => '+52 555 000 1234',
        'rooms' => 28,
        'plan_key' => 'pro',
        'message' => 'Queremos centralizar las reservas.',
        'source' => 'landing',
        'privacy' => true,
        'website' => '',
    ])->assertRedirect();

    $this->assertDatabaseHas('plan_prospects', [
        'name' => 'Ana Torres',
        'hotel_name' => 'Hotel del Lago',
        'email' => 'ana@hotel.test',
        'plan_key' => 'pro',
        'plan_label' => 'Pro',
        'status' => 'new',
    ]);
});

test('no se puede solicitar un plan inactivo', function () {
    Plan::query()->findOrFail('basic')->update(['active' => false]);

    $this->post(route('prospects.store'), [
        'name' => 'Ana Torres',
        'hotel_name' => 'Hotel del Lago',
        'email' => 'ana@hotel.test',
        'phone' => '5550001234',
        'plan_key' => 'basic',
        'privacy' => true,
    ])->assertSessionHasErrors('plan_key');

    expect(PlanProspect::query()->count())->toBe(0);
});

test('el admin puede consultar y dar seguimiento a prospectos', function () {
    $prospect = PlanProspect::factory()->create([
        'hotel_name' => 'Hotel Mirador',
        'plan_key' => 'pro',
        'plan_label' => 'Pro',
    ]);

    $this->actingAs(platformAdminForProspects())
        ->get(route('admin.prospects'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/prospects/Index')
            ->where('stats.total', 1)
            ->where('stats.new', 1)
            ->where('prospects.data.0.hotel_name', 'Hotel Mirador')
        );

    $this->patch(route('admin.prospects.update', $prospect), [
        'status' => 'qualified',
        'notes' => 'Solicitó una demo para el lunes.',
    ])->assertRedirect();

    $prospect->refresh();

    expect($prospect->status)->toBe('qualified')
        ->and($prospect->notes)->toBe('Solicitó una demo para el lunes.')
        ->and($prospect->contacted_at)->not->toBeNull();
});

test('el área de prospectos requiere acceso de plataforma', function () {
    $this->get(route('admin.prospects'))->assertRedirect(route('login'));
});
