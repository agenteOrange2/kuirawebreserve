<?php

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Payments\PaymentGuestNotifier;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '601',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 800,
    ]);
});

function arrivalSoonSettings(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

/**
 * Reserva confirmada cuya llegada es dentro de $inHours horas. Se crea
 * como hold y se confirma con forceFill para no disparar el aviso de
 * "nace confirmada" durante el arrange.
 */
function makeArrivalSoonReservation(int $inHours = 1): \App\Models\Reservation
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => false,
        'guest_name' => 'Llega En Un Rato',
    ]);

    $reservation->forceFill([
        'status' => ReservationStatus::Confirmed,
        'hold_expires_at' => null,
        'starts_at' => now()->addHours($inHours),
        'ends_at' => now()->addHours($inHours + 12),
    ])->saveQuietly();

    return $reservation->refresh();
}

it('sella y avisa a la confirmada que llega en una hora, sin duplicar en la segunda corrida', function () {
    $this->mock(PaymentGuestNotifier::class)
        ->shouldReceive('arrivalSoonReminder')
        ->once();

    $reservation = makeArrivalSoonReservation(1);

    $this->artisan('reservations:arrival-reminders')->assertSuccessful();

    $reservation->refresh();
    expect($reservation->arrival_soon_reminder_sent_at)->not->toBeNull();
    $stamp = $reservation->arrival_soon_reminder_sent_at;

    // Segunda corrida: el sello la excluye (el mock exige once() en total).
    $this->artisan('reservations:arrival-reminders')->assertSuccessful();
    expect($reservation->refresh()->arrival_soon_reminder_sent_at)->toEqual($stamp);
});

it('una llegada todavía lejana espera su ventana', function () {
    $this->mock(PaymentGuestNotifier::class)
        ->shouldNotReceive('arrivalSoonReminder');

    $reservation = makeArrivalSoonReservation(6);

    $this->artisan('reservations:arrival-reminders')->assertSuccessful();

    expect($reservation->refresh()->arrival_soon_reminder_sent_at)->toBeNull();
});

it('respeta las horas configuradas por el hotel', function () {
    arrivalSoonSettings(['arrival_soon_hours' => 8]);

    $this->mock(PaymentGuestNotifier::class)
        ->shouldReceive('arrivalSoonReminder')
        ->once();

    $reservation = makeArrivalSoonReservation(6);

    $this->artisan('reservations:arrival-reminders')->assertSuccessful();

    expect($reservation->refresh()->arrival_soon_reminder_sent_at)->not->toBeNull();
});

it('con el aviso apagado no sella ni manda nada', function () {
    arrivalSoonSettings(['arrival_soon_enabled' => false]);

    $this->mock(PaymentGuestNotifier::class)
        ->shouldNotReceive('arrivalSoonReminder');

    $reservation = makeArrivalSoonReservation(1);

    $this->artisan('reservations:arrival-reminders')->assertSuccessful();

    expect($reservation->refresh()->arrival_soon_reminder_sent_at)->toBeNull();
});
