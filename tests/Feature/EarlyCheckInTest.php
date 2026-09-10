<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Tenant\ReservationController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * El plano mostraba "Registrar llegada" para la reserva de mañana igual que
 * para la de hoy: un clic distraído abría la estancia con un día de
 * anticipación. Y no es cosmético — la estancia hereda planned_end_at y el
 * importe de la reserva, así que la noche de más se regala.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '104',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3500,
    ]);
});

function reservaQueLlega(\DateTimeInterface $start, \DateTimeInterface $end): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'confirmed' => true,
        'guest_name' => 'Llegó antes',
    ]);
}

function checkInRequest(\App\Models\Reservation $reservation, array $body = [])
{
    $request = Request::create("/api/reservations/{$reservation->id}/check-in", 'PATCH', $body);
    $request->setUserResolver(fn () => null);

    return app(ReservationController::class)->checkIn($request, $reservation, app(TransitionReservation::class));
}

it('no registra sola la llegada de una reserva de otro día', function () {
    $reservation = reservaQueLlega(now()->addDay()->setTime(14, 0), now()->addDays(2)->setTime(11, 0));

    app(TransitionReservation::class)->checkIn($reservation);
})->throws(NoAvailabilityException::class, 'confirma la llegada anticipada');

it('la registra cuando quien atiende confirma que el huésped ya está aquí', function () {
    $reservation = reservaQueLlega(now()->addDay()->setTime(14, 0), now()->addDays(2)->setTime(11, 0));

    $response = checkInRequest($reservation, ['early' => 1]);

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::CheckedIn)
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Occupied->value);
});

it('llegar temprano el mismo día es operación normal, sin bandera', function () {
    // Entra a las 15:00 y se presenta a media mañana: eso no es anticipar.
    $reservation = reservaQueLlega(now()->addHours(4), now()->addDay()->setTime(11, 0));

    $stay = app(TransitionReservation::class)->checkIn($reservation);

    expect($stay->status)->toBe(\App\Models\Stay::STATUS_ACTIVE);
});

it('el check-in automático no necesita la bandera: solo mira llegadas cumplidas', function () {
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], ['checkin_mode' => 'auto'])]);

    $futura = reservaQueLlega(now()->addDay()->setTime(14, 0), now()->addDays(2)->setTime(11, 0));

    $this->artisan('reservations:auto-checkin')->assertSuccessful();

    expect($futura->refresh()->status)->toBe(ReservationStatus::Confirmed);

    // Y con la hora cumplida entra sola, como siempre.
    $futura->forceFill(['starts_at' => now()->subMinutes(5)])->saveQuietly();
    $this->artisan('reservations:auto-checkin')->assertSuccessful();

    expect($futura->refresh()->status)->toBe(ReservationStatus::CheckedIn);
});
