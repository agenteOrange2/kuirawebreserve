<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Exceptions\NoAvailabilityException;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Las tablas de dominio viven en las migraciones tenant.
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->rooms = collect(['101', '102'])->map(fn (string $number) => Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => $number,
    ]));
    $this->nightPlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);
});

function makeReservation(array $overrides = []): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->nightPlan->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'guest_name' => 'Huésped Test',
        'confirmed' => true,
        ...$overrides,
    ]);
}

it('calcula precio por noches y por bloques', function () {
    $start = now()->addDay()->setTime(15, 0);
    $end = now()->addDays(3)->setTime(12, 0); // 2 noches

    expect($this->nightPlan->unitsFor($start, $end))->toBe(2)
        ->and($this->nightPlan->priceFor($start, $end))->toBe(1000.0);

    $block = RatePlan::factory()->block(180, 250)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    // 4 horas en bloques de 3h → 2 bloques.
    expect($block->unitsFor(now(), now()->addHours(4)))->toBe(2)
        ->and($block->priceFor(now(), now()->addHours(4)))->toBe(500.0);
});

it('asigna habitación disponible y evita la doble reserva', function () {
    $first = makeReservation(['room_id' => $this->rooms[0]->id]);

    expect($first->room_id)->toBe($this->rooms[0]->id)
        ->and((float) $first->total_amount)->toBe(1000.0);

    // Misma habitación, rango solapado → rechazada.
    makeReservation(['room_id' => $this->rooms[0]->id]);
})->throws(NoAvailabilityException::class);

it('auto-asigna otra habitación del tipo cuando una está tomada', function () {
    makeReservation(['room_id' => $this->rooms[0]->id]);

    $second = makeReservation(); // sin habitación específica

    expect($second->room_id)->toBe($this->rooms[1]->id);

    // Tercera del mismo tipo: ya no hay habitaciones.
    makeReservation();
})->throws(NoAvailabilityException::class);

it('permite rangos consecutivos sin solape', function () {
    makeReservation([
        'room_id' => $this->rooms[0]->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
    ]);

    $next = makeReservation([
        'room_id' => $this->rooms[0]->id,
        'starts_at' => now()->addDays(2)->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
    ]);

    expect($next->room_id)->toBe($this->rooms[0]->id);
});

it('un hold vigente bloquea y un hold vencido no', function () {
    $hold = makeReservation(['room_id' => $this->rooms[0]->id, 'confirmed' => false]);

    expect($hold->status)->toBe(ReservationStatus::Pending)
        ->and($hold->hold_expires_at)->not->toBeNull();

    $service = app(AvailabilityService::class);
    $start = $hold->starts_at;
    $end = $hold->ends_at;

    expect($service->availableRooms($this->roomType->id, $start, $end)->pluck('id'))
        ->not->toContain($this->rooms[0]->id);

    // Vence el hold → vuelve a estar disponible sin tocar nada más.
    $hold->update(['hold_expires_at' => now()->subMinute()]);

    expect($service->availableRooms($this->roomType->id, $start, $end)->pluck('id'))
        ->toContain($this->rooms[0]->id);
});

it('cancelar libera la disponibilidad', function () {
    $reservation = makeReservation(['room_id' => $this->rooms[0]->id]);

    app(TransitionReservation::class)->cancel($reservation);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);

    $again = makeReservation(['room_id' => $this->rooms[0]->id]);
    expect($again->room_id)->toBe($this->rooms[0]->id);
});

it('check-in crea la estancia y ocupa; check-out deja sucia', function () {
    $reservation = makeReservation(['room_id' => $this->rooms[0]->id]);

    $stay = app(TransitionReservation::class)->checkIn($reservation);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::CheckedIn)
        ->and($stay->status)->toBe(Stay::STATUS_ACTIVE)
        ->and($this->rooms[0]->refresh()->status->getMorphClass())->toBe('occupied');

    app(TransitionReservation::class)->checkOut($stay);

    expect($stay->refresh()->status)->toBe(Stay::STATUS_COMPLETED)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Completed)
        ->and($this->rooms[0]->refresh()->status->getMorphClass())->toBe('dirty');
});

it('walk-in ocupa de inmediato y bloquea la habitación', function () {
    $block = RatePlan::factory()->block(180, 250)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->rooms[0]->id,
        'rate_plan_id' => $block->id,
    ]);

    expect((float) $stay->amount)->toBe(250.0)
        ->and($this->rooms[0]->refresh()->status->getMorphClass())->toBe('occupied');

    // Reservar esa habitación en el rango del rato → rechazado.
    makeReservation([
        'room_id' => $this->rooms[0]->id,
        'starts_at' => now()->addMinutes(30),
        'ends_at' => now()->addMinutes(90),
    ]);
})->throws(NoAvailabilityException::class);

it('excluye habitaciones en mantenimiento', function () {
    $this->rooms[0]->update(['status' => 'maintenance']);

    $available = app(AvailabilityService::class)->availableRooms(
        $this->roomType->id,
        now()->addDay(),
        now()->addDays(2),
    );

    expect($available->pluck('id'))->not->toContain($this->rooms[0]->id)
        ->and($available->pluck('id'))->toContain($this->rooms[1]->id);
});
