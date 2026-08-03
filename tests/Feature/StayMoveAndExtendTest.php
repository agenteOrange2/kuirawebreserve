<?php

use App\Actions\Reservations\ChangeStayRoom;
use App\Actions\Reservations\ExtendStay;
use App\Enums\RoomStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'occupied',
    ]);
    $this->other = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
        'status' => 'available',
    ]);
    $this->user = User::factory()->create();
});

function activeStay(array $overrides = []): Stay
{
    return Stay::create(array_replace([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped',
        'num_people' => 2,
        'check_in_at' => now()->subDay(),
        'planned_end_at' => now()->addHours(4),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1000,
        'channel' => 'walk_in',
    ], $overrides));
}

it('extender la estancia mueve la salida y recalcula el hospedaje', function () {
    $stay = activeStay();
    $nuevoFin = now()->addDays(2);

    app(ExtendStay::class)->handle($stay, $nuevoFin, $this->user);

    $stay->refresh();

    expect($stay->planned_end_at->toDateTimeString())->toBe($nuevoFin->toDateTimeString())
        // Dos noches en vez de una: el hospedaje sube.
        ->and((float) $stay->amount)->toBeGreaterThan(1000.0);
});

it('lo ya pagado no se pierde: la diferencia queda como saldo del folio', function () {
    $stay = activeStay();
    $stay->payments()->create([
        'amount' => 1000,
        'method' => 'cash',
        'kind' => \App\Models\Payment::KIND_LODGING,
        'received_by' => $this->user->id,
        'paid_at' => now(),
        'created_at' => now(),
    ]);

    app(ExtendStay::class)->handle($stay, now()->addDays(2), $this->user);

    $folio = $stay->refresh()->folio();

    expect($folio['lodging_paid'])->toBe(1000.0)
        ->and($folio['lodging_pending'])->toBeGreaterThan(0.0);
});

it('no se extiende sobre una reserva que ya tiene esa habitación tomada', function () {
    $stay = activeStay();

    Reservation::create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_name' => 'Quien llega después',
        'num_people' => 2,
        'starts_at' => now()->addHours(6),
        'ends_at' => now()->addDays(2),
        'status' => \App\Enums\ReservationStatus::Confirmed,
        'total_amount' => 1000,
        'source_channel' => 'front_desk',
    ]);

    expect(fn () => app(ExtendStay::class)->handle($stay, now()->addDays(3), $this->user))
        ->toThrow(NoAvailabilityException::class);
});

it('no se extiende sobre un bloqueo de mantenimiento', function () {
    $stay = activeStay();

    RoomBlock::create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(3),
        'reason' => 'Pintura',
    ]);

    expect(fn () => app(ExtendStay::class)->handle($stay, now()->addDays(2), $this->user))
        ->toThrow(NoAvailabilityException::class);
});

it('la nueva salida tiene que ser posterior a la actual', function () {
    $stay = activeStay();

    expect(fn () => app(ExtendStay::class)->handle($stay, now()->addHour(), $this->user))
        ->toThrow(InvalidArgumentException::class);
});

it('cambiar de habitación mueve al huésped y deja sucia la que dejó', function () {
    $stay = activeStay();

    app(ChangeStayRoom::class)->handle($stay, $this->other, $this->user);

    expect($stay->refresh()->room_id)->toBe($this->other->id)
        ->and($this->other->refresh()->status->getMorphClass())->toBe(RoomStatus::Occupied->value)
        // La que deja se usó, aunque haya sido un rato: alguien la revisa.
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Dirty->value);
});

it('por defecto el cambio de habitación no recobra: es cortesía del hotel', function () {
    $this->other->update(['price_modifier' => 500]);
    $stay = activeStay();

    app(ChangeStayRoom::class)->handle($stay, $this->other->refresh(), $this->user);

    expect((float) $stay->refresh()->amount)->toBe(1000.0);
});

it('con recalcular, el precio toma el modificador de la habitación nueva', function () {
    $this->other->update(['price_modifier' => 500]);
    $stay = activeStay();

    app(ChangeStayRoom::class)->handle($stay, $this->other->refresh(), $this->user, true);

    expect((float) $stay->refresh()->amount)->toBeGreaterThan(1000.0);
});

it('la reserva de origen se mueve con el huésped', function () {
    $reservation = Reservation::create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_name' => 'Huésped',
        'num_people' => 2,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addHours(4),
        'status' => \App\Enums\ReservationStatus::CheckedIn,
        'total_amount' => 1000,
        'source_channel' => 'front_desk',
    ]);
    $stay = activeStay(['reservation_id' => $reservation->id]);

    app(ChangeStayRoom::class)->handle($stay, $this->other, $this->user);

    expect($reservation->refresh()->room_id)->toBe($this->other->id);
});

it('no se mueve a una habitación ocupada', function () {
    $stay = activeStay();
    $this->other->update(['status' => 'occupied']);

    expect(fn () => app(ChangeStayRoom::class)->handle($stay, $this->other->refresh(), $this->user))
        ->toThrow(NoAvailabilityException::class);
});

it('no se mueve a una habitación de otro tipo: la tarifa no le corresponde', function () {
    $otroTipo = RoomType::factory()->create(['property_id' => $this->property->id]);
    $cuartoOtroTipo = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $otroTipo->id,
        'number' => '201',
        'status' => 'available',
    ]);
    $stay = activeStay();

    expect(fn () => app(ChangeStayRoom::class)->handle($stay, $cuartoOtroTipo, $this->user))
        ->toThrow(InvalidArgumentException::class);
});

it('una estancia ya cerrada no se mueve ni se extiende', function () {
    $stay = activeStay(['status' => Stay::STATUS_COMPLETED]);

    expect(fn () => app(ChangeStayRoom::class)->handle($stay, $this->other, $this->user))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ExtendStay::class)->handle($stay, now()->addDays(2), $this->user))
        ->toThrow(InvalidArgumentException::class);
});
