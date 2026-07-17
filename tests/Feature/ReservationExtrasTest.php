<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Extra;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);
    $this->decoracion = Extra::factory()->create(['property_id' => $this->property->id, 'name' => 'Decoración romántica', 'price' => 350]);
    $this->desayuno = Extra::factory()->create(['property_id' => $this->property->id, 'name' => 'Desayuno', 'price' => 120]);
});

function reservarConExtras(array $extras): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'confirmed' => true,
        'extras' => $extras,
    ]);
}

it('los extras suman al total y quedan congelados en la reserva', function () {
    $reservation = reservarConExtras([
        ['extra_id' => $this->decoracion->id, 'qty' => 1],
        ['extra_id' => $this->desayuno->id, 'qty' => 2],
    ]);

    // 1000 (noche) + 350 + 240
    expect((float) $reservation->total_amount)->toBe(1590.0)
        ->and($reservation->extras)->toHaveCount(2)
        ->and($reservation->extras[0]['name'])->toBe('Decoración romántica')
        ->and($reservation->extras[1]['total'])->toBe(240);

    // El anticipo/saldo parten del total: subir el precio del catálogo
    // DESPUÉS no toca lo vendido.
    $this->decoracion->update(['price' => 999]);
    expect((float) $reservation->fresh()->total_amount)->toBe(1590.0);
});

it('ignora extras inactivos o inexistentes sin tronar', function () {
    $this->decoracion->update(['active' => false]);

    $reservation = reservarConExtras([
        ['extra_id' => $this->decoracion->id, 'qty' => 1],
        ['extra_id' => 99999, 'qty' => 1],
        ['extra_id' => $this->desayuno->id, 'qty' => 1],
    ]);

    expect((float) $reservation->total_amount)->toBe(1120.0)
        ->and($reservation->extras)->toHaveCount(1);
});

it('sin extras la reserva queda igual que siempre', function () {
    $reservation = reservarConExtras([]);

    expect((float) $reservation->total_amount)->toBe(1000.0)
        ->and($reservation->extras)->toBeNull();
});
