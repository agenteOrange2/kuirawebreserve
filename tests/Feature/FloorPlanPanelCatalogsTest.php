<?php

use App\Http\Controllers\Tenant\FloorPlanController;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Catálogos que el plano entrega para el panel de habitación (módulo
 * plano-operativo): dar de alta o editar desde ahí necesita la lista de tipos
 * y de zonas, y quien no administra habitaciones no tiene por qué recibirla.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->zone = Zone::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Planta baja',
    ]);
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Suite Deluxe',
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'zone_id' => $this->zone->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'available',
    ]);

    Permission::findOrCreate('rooms.view', 'web');
    Permission::findOrCreate('rooms.update-status', 'web');
    Permission::findOrCreate('rooms.manage', 'web');
});

function panelProps(User $user): array
{
    $request = Request::create('/plano', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => $user);

    return app(FloorPlanController::class)($request)
        ->toResponse($request)->getData(true)['props'];
}

it('quien administra habitaciones recibe tipos y zonas para el panel', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(['rooms.view', 'rooms.update-status', 'rooms.manage']);

    $props = panelProps($user);

    expect($props['roomTypes'])->toHaveCount(1)
        ->and($props['roomTypes'][0]['name'])->toBe('Suite Deluxe')
        ->and($props['zones'])->toHaveCount(1)
        ->and($props['zones'][0]['name'])->toBe('Planta baja');
});

it('sin rooms.manage los catálogos llegan vacíos: el panel no puede dar de alta', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(['rooms.view', 'rooms.update-status']);

    $props = panelProps($user);

    expect($props['roomTypes'])->toBe([])
        ->and($props['zones'])->toBe([]);
});

it('la habitación viaja con el id de su tipo para poder editarlo desde el panel', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(['rooms.view', 'rooms.update-status', 'rooms.manage']);

    $room = panelProps($user)['rooms'][0];

    expect($room['room_type_id'])->toBe($this->roomType->id)
        ->and($room['zone_id'])->toBe($this->zone->id);
});

it('el historial de la habitación lista sus estancias con lo que consumieron', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(['rooms.view', 'rooms.update-status']);

    $plan = \App\Models\RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $vieja = \App\Models\Stay::create([
        'room_id' => $this->room->id,
        'rate_plan_id' => $plan->id,
        'guest_name' => 'Quien se fue',
        'num_people' => 1,
        'check_in_at' => now()->subDays(2),
        'planned_end_at' => now()->subDays(2)->addHours(12),
        'check_out_at' => now()->subDays(2)->addHours(11),
        'status' => \App\Models\Stay::STATUS_COMPLETED,
        'amount' => 900,
        'channel' => 'walk_in',
        'vehicle_plate' => 'XYZ-123-A',
    ]);

    \App\Models\Order::create([
        'property_id' => $this->property->id,
        'stay_id' => $vieja->id,
        'status' => \App\Models\Order::STATUS_COMPLETED,
        'payment_method' => 'cash',
        'total' => 150,
        'total_cost' => 60,
    ]);

    // Cancelada: no cuenta como consumo.
    \App\Models\Order::create([
        'property_id' => $this->property->id,
        'stay_id' => $vieja->id,
        'status' => \App\Models\Order::STATUS_VOID,
        'payment_method' => 'cash',
        'total' => 999,
        'total_cost' => 0,
    ]);

    $request = Request::create("/api/rooms/{$this->room->id}/stays", 'GET');
    $request->setUserResolver(fn () => $user);

    $stays = app(\App\Http\Controllers\Tenant\RoomController::class)
        ->stays($this->room)->getData(true)['stays'];

    expect($stays)->toHaveCount(1)
        ->and($stays[0]['guest_name'])->toBe('Quien se fue')
        ->and($stays[0]['active'])->toBeFalse()
        ->and((float) $stays[0]['amount'])->toBe(900.0)
        // Solo lo completado: la cancelada no infla el historial.
        ->and((float) $stays[0]['consumos_total'])->toBe(150.0)
        // El motel reconoce la visita por la placa, no por el nombre.
        ->and($stays[0]['vehicle_plate'])->toBe('XYZ-123-A');
});
