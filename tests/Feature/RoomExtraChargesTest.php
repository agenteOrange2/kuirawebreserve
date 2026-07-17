<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\UpdateReservation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(10));

    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'capacity' => 6,
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'available',
        'included_occupancy' => 2,
        'extra_guest_fee' => 150,
        'optional_charges' => [
            ['concept' => 'Mascota', 'amount' => 200],
            ['concept' => 'Decoración', 'amount' => 350],
        ],
    ]);
    $this->nightPlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);
    $this->user = User::factory()->create();
});

it('suma persona extra por unidad y cargos opcionales elegidos en un walk-in', function () {
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->nightPlan->id,
        'planned_end_at' => now()->addDays(2)->setTime(12, 0)->toDateTimeString(),
        'num_people' => 4,
        'extra_charges' => ['Mascota'],
    ], $this->user);

    // 2 noches × $1000 + 2 personas extra × $150 × 2 noches + Mascota $200.
    expect((float) $stay->amount)->toBe(2800.0)
        ->and($stay->extra_charges)->toHaveCount(2)
        ->and($stay->extra_charges[0]['kind'])->toBe('extra_guests')
        ->and((float) $stay->extra_charges[0]['amount'])->toBe(600.0)
        ->and($stay->extra_charges[1]['concept'])->toBe('Mascota')
        ->and((float) $stay->extra_charges[1]['amount'])->toBe(200.0);
});

it('no cobra extra cuando la gente cabe en lo incluido y no se eligen cargos', function () {
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->nightPlan->id,
        'num_people' => 2,
    ], $this->user);

    expect((float) $stay->amount)->toBe(1000.0)
        ->and($stay->extra_charges)->toBeNull();
});

it('ignora conceptos que no existen en la ficha de la habitación', function () {
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->nightPlan->id,
        'num_people' => 1,
        'extra_charges' => ['Cargo inventado por el cliente'],
    ], $this->user);

    expect((float) $stay->amount)->toBe(1000.0)
        ->and($stay->extra_charges)->toBeNull();
});

it('aplica cargos extra en reservas y los recalcula al editar', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->nightPlan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0)->toDateTimeString(),
        'ends_at' => now()->addDays(2)->setTime(12, 0)->toDateTimeString(),
        'adults' => 3,
        'children' => 0,
        'confirmed' => true,
        'extra_charges' => ['Decoración'],
    ], $this->user);

    // 1 noche × $1000 + 1 persona extra × $150 + Decoración $350.
    expect((float) $reservation->total_amount)->toBe(1500.0)
        ->and($reservation->extra_charges)->toHaveCount(2);

    // Editar sin mandar extra_charges: conserva los opcionales elegidos y
    // recalcula la línea de personas (con 2 adultos ya no hay excedente).
    $updated = app(UpdateReservation::class)->handle($reservation, [
        'rate_plan_id' => $this->nightPlan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0)->toDateTimeString(),
        'ends_at' => now()->addDays(2)->setTime(12, 0)->toDateTimeString(),
        'adults' => 2,
    ], $this->user);

    expect((float) $updated->total_amount)->toBe(1350.0)
        ->and($updated->extra_charges)->toHaveCount(1)
        ->and($updated->extra_charges[0]['concept'])->toBe('Decoración');
});
