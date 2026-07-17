<?php

use App\Actions\Reservations\CreateGroupReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla', 'capacity' => 2]);
    // Tres cuartos físicos del mismo tipo.
    Room::factory()->count(3)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);
});

function crearGrupo(array $overrides = []): ReservationGroup
{
    return app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'guest_name' => 'Familia García',
        'guest_phone' => '5544332211',
        'confirmed' => true,
        'lines' => [['room_type_id' => test()->roomType->id, 'rooms' => 3, 'adults' => 2]],
        ...$overrides,
    ]);
}

it('crea el grupo completo con folio GRP y habitaciones distintas', function () {
    $group = crearGrupo();

    expect($group->code)->toStartWith('GRP-'.now()->year)
        ->and($group->reservations)->toHaveCount(3)
        ->and($group->totalAmount())->toBe(3000.0)
        ->and($group->reservations->pluck('room_id')->unique())->toHaveCount(3)
        ->and($group->guest_id)->not->toBeNull();
});

it('todo o nada: sin cuartos suficientes NO se crea ninguna reserva', function () {
    // Solo hay 3 cuartos; pedir 4 debe reventar sin dejar rastro.
    expect(fn () => crearGrupo(['lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 4, 'adults' => 2]]]))
        ->toThrow(NoAvailabilityException::class);

    expect(Reservation::count())->toBe(0)
        ->and(ReservationGroup::count())->toBe(0);
});

it('rechaza grupos de una sola habitación', function () {
    crearGrupo(['lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 1]]]);
})->throws(InvalidArgumentException::class, 'dos habitaciones');

it('cancelar el grupo libera todas las habitaciones', function () {
    $group = crearGrupo();

    $transition = app(\App\Actions\Reservations\TransitionReservation::class);
    foreach ($group->reservations as $reservation) {
        $transition->cancel($reservation, null, reason: 'Cancelación del grupo.');
    }

    expect($group->reservations()->where('status', ReservationStatus::Cancelled)->count())->toBe(3);

    // Los cuartos quedan libres: un grupo nuevo del mismo tamaño entra.
    $again = crearGrupo(['guest_name' => 'Otro grupo', 'guest_phone' => '5544332212']);
    expect($again->reservations)->toHaveCount(3);
});
