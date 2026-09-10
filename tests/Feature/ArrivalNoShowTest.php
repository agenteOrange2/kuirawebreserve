<?php

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

/**
 * El cierre de día mira la SALIDA. Sin esta ventana, una reserva de varias
 * noches que nadie ocupó mantiene la habitación apartada toda la estancia
 * fantasma: el mostrador ve "reservada" para alguien que no va a llegar.
 */
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
        'price' => 1200,
    ]);
});

function noShowSettings(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

/** Reserva de tres noches que entró hace horas y nadie registró. */
function reservaSinLlegada(int $horasDesdeLaEntrada = 7): \App\Models\Reservation
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(14, 0),
        'ends_at' => now()->addDays(4)->setTime(11, 0),
        'confirmed' => true,
        'guest_name' => 'Nunca llegó',
    ]);

    test()->room->refresh()->forceFill(['status' => RoomStatus::Reserved->value])->saveQuietly();

    $reservation->forceFill([
        'starts_at' => now()->subHours($horasDesdeLaEntrada),
        'ends_at' => now()->addDays(2)->setTime(11, 0),
    ])->saveQuietly();

    return $reservation->refresh();
}

it('marca no llegó y libera la habitación pasada la ventana', function () {
    noShowSettings([
        'arrival_no_show_enabled' => true,
        'arrival_no_show_value' => 6,
        'arrival_no_show_unit' => 'hour',
    ]);

    $reservation = reservaSinLlegada();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::NoShow)
        ->and($reservation->cancellation_reason)->toContain('ventana de llegada')
        // Se marca la reserva y no solo se suelta el semáforo: con la reserva
        // viva, esas fechas seguirían apartadas y el cuarto verde sería
        // invendible.
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Available->value);
});

it('respeta la ventana: dentro del plazo no toca nada', function () {
    noShowSettings([
        'arrival_no_show_enabled' => true,
        'arrival_no_show_value' => 6,
        'arrival_no_show_unit' => 'hour',
    ]);

    $reservation = reservaSinLlegada(2);

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value);
});

it('apagado por default: la reserva sin llegada sigue apartando su habitación', function () {
    $reservation = reservaSinLlegada(30);

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value);
});

it('no toca la reserva cuya llegada sí se registró', function () {
    noShowSettings([
        'arrival_no_show_enabled' => true,
        'arrival_no_show_value' => 1,
        'arrival_no_show_unit' => 'hour',
    ]);

    $reservation = reservaSinLlegada();
    app(\App\Actions\Reservations\TransitionReservation::class)->checkIn($reservation);

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::CheckedIn)
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Occupied->value);
});
