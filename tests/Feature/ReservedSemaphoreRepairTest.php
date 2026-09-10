<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

/**
 * El semáforo "reservada" es un candado SIN fechas, y durante meses quien lo
 * apagaba preguntaba "¿este cuarto tiene alguna reserva futura?" en vez de
 * "¿hay una reserva que lo aparte HOY?". En un hotel con agenda cargada la
 * respuesta era siempre que sí, así que el cierre de día cerraba la reserva
 * vencida y jamás soltaba el cuarto: ocho cabañas pasaron nueve días en
 * "Reservada" sin poder venderse, y ni siquiera se podían liberar a mano.
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

/** Reserva confirmada del cuarto, con las fechas que se le pidan. */
function repairReservation(\DateTimeInterface $start, \DateTimeInterface $end): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'confirmed' => true,
        'guest_name' => 'Huésped',
    ]);
}

/**
 * Deja el cuarto apartado por esa reserva, como lo hace
 * rooms:reserve-arrivals. Si la reserva llega hoy, CreateReservation ya lo
 * apartó al confirmarla y no hay nada que hacer.
 */
function markReserved(Reservation $reservation): void
{
    $room = test()->room->refresh();

    if ($room->status->getMorphClass() === RoomStatus::Reserved->value) {
        return;
    }

    app(ChangeRoomStatus::class)->handle($room, RoomStatus::Reserved->value, null, [
        'reservation_id' => $reservation->id,
        'auto' => true,
    ]);
}

it('libera el semáforo que quedó apartado por una reserva ya cerrada, aunque el cuarto tenga reservas futuras', function () {
    // La reserva de anteayer que encendió el semáforo y ya se cerró.
    $pasada = repairReservation(now()->subDays(2)->setTime(14, 0), now()->subDay()->setTime(11, 0));
    markReserved($pasada);
    $pasada->forceFill(['status' => ReservationStatus::Completed])->saveQuietly();

    // Y la de dentro de un mes, que es la que confundía al cierre de día.
    repairReservation(now()->addMonth()->setTime(14, 0), now()->addMonth()->addDay()->setTime(11, 0));

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    $room = $this->room->refresh();

    expect($room->status->getMorphClass())->toBe(RoomStatus::Available->value)
        // A disponible y no a sucia: nadie entró, y reservada → sucia
        // cuenta un uso que podría dejar el cuarto bajo el candado.
        ->and((int) $room->usage_count)->toBe(0)
        ->and($room->usage_locked_at)->toBeNull();

    $log = $room->statusLogs()->latest('id')->first();

    expect($log->to_status)->toBe(RoomStatus::Available->value)
        ->and($log->context['repair'] ?? null)->toBe('orphan_reserved')
        ->and($log->context['reservation_id'] ?? null)->toBe($pasada->id)
        ->and($log->changed_by)->toBeNull();
});

it('no toca el cuarto que una reserva aparta hoy', function () {
    $hoy = repairReservation(now()->addHours(3), now()->addDay()->setTime(11, 0));
    markReserved($hoy);

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value);
});

it('no se adelanta al cierre de día manual', function () {
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], ['day_close_no_checkin' => 'none'])]);

    $vencida = repairReservation(now()->addDay()->setTime(14, 0), now()->addDays(2)->setTime(11, 0));
    markReserved($vencida);
    // La salida ya pasó, pero el hotel eligió resolverlo a mano: el semáforo
    // no miente, está esperando a que alguien diga si llegaron o no.
    $vencida->forceFill([
        'starts_at' => now()->subDay()->subHour(),
        'ends_at' => now()->subHour(),
    ])->saveQuietly();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($vencida->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value);
});

it('encender y apagar no pelean: el semáforo no parpadea entre corridas', function () {
    $hoy = repairReservation(now()->addHours(4), now()->addDay()->setTime(11, 0));

    $this->artisan('rooms:reserve-arrivals')->assertSuccessful();
    expect($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value);

    $logs = fn () => $this->room->refresh()->statusLogs()->count();
    $antes = $logs();

    for ($i = 0; $i < 3; $i++) {
        $this->artisan('rooms:advance-housekeeping')->assertSuccessful();
        $this->artisan('rooms:reserve-arrivals')->assertSuccessful();
    }

    expect($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Reserved->value)
        ->and($logs())->toBe($antes)
        ->and($hoy->refresh()->status)->toBe(ReservationStatus::Confirmed);
});

it('una pendiente sin caducidad no congela el semáforo', function () {
    $pasada = repairReservation(now()->subDays(2)->setTime(14, 0), now()->subDay()->setTime(11, 0));
    markReserved($pasada);
    $pasada->forceFill(['status' => ReservationStatus::Completed])->saveQuietly();

    // Apartado abandonado, sin hold: no bloquea disponibilidad (scopeBlocking
    // lo ignora), así que tampoco tiene por qué apartar el cuarto físico.
    repairReservation(now()->addWeek()->setTime(14, 0), now()->addWeek()->addDay()->setTime(11, 0))
        ->forceFill(['status' => ReservationStatus::Pending, 'hold_expires_at' => null])
        ->saveQuietly();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Available->value);
});
