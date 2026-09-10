<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Exceptions\NoAvailabilityException;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

/**
 * El caso que reportó el dueño: la 104 tenía una reserva del 11/09 14:00 al
 * 12/09 11:00 y el plano no dejaba venderla el 09/09, aunque las fechas no
 * se tocan. "Apartada" es una promesa de FECHAS, no un candado del cuarto.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 4]);
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

    // Reserva de pasado mañana y semáforo apartado, como lo deja el cron.
    $this->futura = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(2)->setTime(14, 0),
        'ends_at' => now()->addDays(3)->setTime(11, 0),
        'confirmed' => true,
        'guest_name' => 'Lizbeth',
    ]);

    $this->room->forceFill(['status' => RoomStatus::Reserved->value])->saveQuietly();
});

function walkIn(array $extra = [])
{
    return app(CreateWalkInStay::class)->handle(array_merge([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Quien llegó hoy',
        'num_people' => 2,
    ], $extra));
}

it('vende hoy una habitación apartada para otra fecha', function () {
    $stay = walkIn(['planned_end_at' => now()->addDay()->setTime(11, 0)]);

    expect($stay->room_id)->toBe($this->room->id)
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Occupied->value)
        // La reserva de pasado mañana sigue en pie: no se pisó nada.
        ->and($this->futura->refresh()->room_id)->toBe($this->room->id);
});

it('rechaza la estancia que sí pisa la reserva, y lo dice por las fechas', function () {
    walkIn(['planned_end_at' => now()->addDays(2)->setTime(20, 0)]);
})->throws(NoAvailabilityException::class, 'ya no está disponible en ese horario');

it('rechaza la habitación que no está para entregarse, y lo dice por el cuarto', function () {
    $this->room->forceFill(['status' => RoomStatus::Dirty->value])->saveQuietly();

    walkIn(['planned_end_at' => now()->addDay()->setTime(11, 0)]);
})->throws(NoAvailabilityException::class, 'libérala desde el plano');
