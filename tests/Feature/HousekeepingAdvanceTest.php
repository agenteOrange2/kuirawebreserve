<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '201',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 900,
    ]);
});

function hkSettings(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

/** Reserva confirmada que ya venció (salida hace N minutos) con su habitación apartada. */
function makeOverdueReservedRoom(int $minutesPastEnd = 60): \App\Models\Reservation
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Huésped Fantasma',
    ]);

    app(ChangeRoomStatus::class)->handle(test()->room->refresh(), RoomStatus::Reserved->value, null, [
        'reservation_id' => $reservation->id,
    ]);

    // Se simula que la salida ya pasó (sin pasar por validaciones de fechas).
    $reservation->forceFill([
        'starts_at' => now()->subDay()->subMinutes($minutesPastEnd),
        'ends_at' => now()->subMinutes($minutesPastEnd),
    ])->saveQuietly();

    return $reservation->refresh();
}

/** Deja la habitación en un estado y retrocede su último log N minutos. */
function putRoomInState(string $status, int $minutesAgo): void
{
    test()->room->refresh()->forceFill(['status' => $status])->saveQuietly();
    test()->room->statusLogs()->create([
        'from_status' => RoomStatus::Occupied->value,
        'to_status' => $status,
    ])->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
}

it('cierre de día default: la reservada vencida se asume ocupada y cae a sucia', function () {
    $reservation = makeOverdueReservedRoom();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Completed)
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Dirty->value);

    $log = test()->room->statusLogs()->latest('id')->first();
    expect($log->to_status)->toBe(RoomStatus::Dirty->value)
        ->and($log->context['auto'] ?? false)->toBeTrue()
        ->and($log->changed_by)->toBeNull();
});

it('cierre de día en modo liberar: no-show y la habitación vuelve a disponible', function () {
    hkSettings(['day_close_no_checkin' => 'available']);
    $reservation = makeOverdueReservedRoom();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::NoShow)
        ->and($reservation->cancellation_reason)->toContain('Cierre de día')
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Available->value);
});

it('cierre de día apagado: nada se toca', function () {
    hkSettings(['day_close_no_checkin' => 'none']);
    $reservation = makeOverdueReservedRoom();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value);
});

it('respeta la gracia: una salida recién vencida todavía no se cierra', function () {
    $reservation = makeOverdueReservedRoom(5); // gracia default: 15 min

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value);
});

it('en modo automático la sucia pasa a limpieza y la limpieza a disponible al cumplirse el tiempo', function () {
    hkSettings([
        'hk_mode' => 'auto',
        'hk_dirty_value' => 30,
        'hk_dirty_unit' => 'minute',
        'hk_cleaning_value' => 45,
        'hk_cleaning_unit' => 'minute',
    ]);

    putRoomInState(RoomStatus::Dirty->value, 40);
    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();
    expect(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Cleaning->value);

    // El reloj de limpieza inicia con el cambio recién hecho: aún no libera.
    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();
    expect(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Cleaning->value);

    // Se retrocede el log del cambio a limpieza y ahora sí libera.
    test()->room->statusLogs()->latest('id')->first()
        ->forceFill(['created_at' => now()->subMinutes(50)])->saveQuietly();
    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();
    expect(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Available->value);
});

it('antes de cumplirse el tiempo la sucia no se mueve, y en modo manual nunca', function () {
    hkSettings(['hk_mode' => 'both', 'hk_dirty_value' => 30, 'hk_dirty_unit' => 'minute']);
    putRoomInState(RoomStatus::Dirty->value, 10);

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();
    expect(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Dirty->value);

    hkSettings(['hk_mode' => 'manual']);
    test()->room->statusLogs()->latest('id')->first()
        ->forceFill(['created_at' => now()->subHours(5)])->saveQuietly();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();
    expect(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Dirty->value);
});

it('en modo automático puro el plano no ofrece los botones de limpieza; en ambos sí', function () {
    hkSettings(['hk_mode' => 'auto']);
    putRoomInState(RoomStatus::Dirty->value, 1);

    expect(test()->room->refresh()->manualStatusTransitions())
        ->not->toContain(RoomStatus::Cleaning->value)
        ->toContain(RoomStatus::Maintenance->value);

    hkSettings(['hk_mode' => 'both']);
    expect(test()->room->refresh()->manualStatusTransitions())
        ->toContain(RoomStatus::Cleaning->value);
});

it('una reservada con reserva viva no se puede mandar a sucia a mano; una vencida sí', function () {
    $reservation = makeOverdueReservedRoom();

    // Vencida (sin reserva viva): se puede soltar a mano a sucia o disponible.
    expect(test()->room->refresh()->manualStatusTransitions())
        ->toContain(RoomStatus::Dirty->value)
        ->toContain(RoomStatus::Available->value);

    // Con la reserva vigente de nuevo (llegada hoy, salida mañana): bloqueada.
    $reservation->forceFill([
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addDay(),
    ])->saveQuietly();

    expect(test()->room->refresh()->manualStatusTransitions())
        ->not->toContain(RoomStatus::Dirty->value)
        ->not->toContain(RoomStatus::Available->value);
});

it('una habitación por limpiar puede liberarse en un solo paso a disponible', function () {
    hkSettings(['hk_mode' => 'manual']);

    // occupied → dirty → available directo, sin pasar por "en limpieza".
    app(ChangeRoomStatus::class)->handle($this->room->refresh(), RoomStatus::Occupied->value);
    app(ChangeRoomStatus::class)->handle($this->room->refresh(), RoomStatus::Dirty->value);

    $room = $this->room->refresh();

    // El liberado directo aparece entre las transiciones manuales del plano.
    expect($room->manualStatusTransitions())->toContain(RoomStatus::Available->value);

    app(ChangeRoomStatus::class)->handle($room, RoomStatus::Available->value, null);

    expect($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Available->value);
});

it('en modo automático puro el liberado directo también se oculta', function () {
    hkSettings(['hk_mode' => 'auto']);

    app(ChangeRoomStatus::class)->handle($this->room->refresh(), RoomStatus::Occupied->value);
    app(ChangeRoomStatus::class)->handle($this->room->refresh(), RoomStatus::Dirty->value);

    $transitions = $this->room->refresh()->manualStatusTransitions();

    expect($transitions)->not->toContain(RoomStatus::Available->value)
        ->and($transitions)->not->toContain(RoomStatus::Cleaning->value);
});
