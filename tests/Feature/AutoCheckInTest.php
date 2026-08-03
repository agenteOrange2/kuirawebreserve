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
use App\Models\Stay;
use App\Services\HousekeepingPolicy;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '301',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 700,
    ]);
});

function checkinSettings(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

/**
 * Reserva confirmada cuya hora de llegada ya pasó (salida aún futura),
 * con la habitación en el estado indicado.
 */
function makeDueArrival(string $roomStatus = 'reserved', string $reservationStatus = 'confirmed'): \App\Models\Reservation
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => $reservationStatus === 'confirmed',
        'guest_name' => 'Llegada Puntual',
    ]);

    if ($roomStatus !== RoomStatus::Available->value) {
        test()->room->refresh()->forceFill(['status' => $roomStatus])->saveQuietly();
    }

    // La hora de llegada ya pasó; la salida sigue en el futuro.
    $reservation->forceFill([
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(10),
    ])->saveQuietly();

    return $reservation->refresh();
}

it('en modo manual (default) el reloj no registra llegadas', function () {
    $reservation = makeDueArrival();

    $this->artisan('reservations:auto-checkin')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value)
        ->and(Stay::query()->count())->toBe(0);
});

it('en modo ambos, al pasar la hora se abre la estancia y la habitación queda ocupada', function () {
    checkinSettings(['checkin_mode' => 'both']);
    $reservation = makeDueArrival();

    $this->artisan('reservations:auto-checkin')->assertSuccessful();

    $reservation->refresh();
    $stay = Stay::query()->first();

    expect($reservation->status)->toBe(ReservationStatus::CheckedIn)
        ->and($stay)->not->toBeNull()
        ->and($stay->reservation_id)->toBe($reservation->id)
        ->and($stay->status)->toBe(Stay::STATUS_ACTIVE)
        ->and($stay->planned_end_at->toDateTimeString())->toBe($reservation->ends_at->toDateTimeString())
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Occupied->value);

    // El log del semáforo marca que fue automático (visible en el plano).
    $log = test()->room->statusLogs()->latest('id')->first();
    expect($log->to_status)->toBe(RoomStatus::Occupied->value)
        ->and($log->context['auto'] ?? false)->toBeTrue()
        ->and($log->changed_by)->toBeNull();
});

it('antes de la hora de llegada no toca la reserva', function () {
    checkinSettings(['checkin_mode' => 'auto']);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHours(3),
        'ends_at' => now()->addHours(15),
        'confirmed' => true,
        'guest_name' => 'Llega Más Tarde',
    ]);

    $this->artisan('reservations:auto-checkin')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and(Stay::query()->count())->toBe(0);
});

it('una pendiente sin confirmar nunca hace check-in sola', function () {
    checkinSettings(['checkin_mode' => 'auto']);
    $reservation = makeDueArrival(RoomStatus::Available->value, 'pending');

    $this->artisan('reservations:auto-checkin')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and(Stay::query()->count())->toBe(0);
});

it('si la habitación sigue sucia espera, y al liberarse el check-in cae solo', function () {
    checkinSettings(['checkin_mode' => 'auto']);
    $reservation = makeDueArrival(RoomStatus::Dirty->value);

    $this->artisan('reservations:auto-checkin')->assertSuccessful();
    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and(Stay::query()->count())->toBe(0);

    // Housekeeping la libera; la siguiente corrida registra la llegada.
    app(ChangeRoomStatus::class)->handle(test()->room->refresh(), RoomStatus::Cleaning->value);
    app(ChangeRoomStatus::class)->handle(test()->room->refresh(), RoomStatus::Available->value);

    $this->artisan('reservations:auto-checkin')->assertSuccessful();
    expect($reservation->refresh()->status)->toBe(ReservationStatus::CheckedIn)
        ->and(test()->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Occupied->value);
});

it('los modos exponen bien quién puede hacer check-in manual', function () {
    expect(app(HousekeepingPolicy::class)->manualCheckInAllowed())->toBeTrue()
        ->and(app(HousekeepingPolicy::class)->autoCheckIn())->toBeFalse();

    checkinSettings(['checkin_mode' => 'auto']);
    expect(app(HousekeepingPolicy::class)->manualCheckInAllowed())->toBeFalse()
        ->and(app(HousekeepingPolicy::class)->autoCheckIn())->toBeTrue();

    checkinSettings(['checkin_mode' => 'both']);
    expect(app(HousekeepingPolicy::class)->manualCheckInAllowed())->toBeTrue()
        ->and(app(HousekeepingPolicy::class)->autoCheckIn())->toBeTrue();
});
