<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\RoomController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(10));

    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '103',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3500,
    ]);
    $this->user = User::factory()->create();
});

function updateRoomStatus(Room $room, string $status, ?User $actor = null): JsonResponse
{
    $request = Request::create('/api', 'PATCH', ['status' => $status]);
    $request->setUserResolver(fn () => $actor);

    return app(RoomController::class)->updateStatus($request, $room->refresh(), app(ChangeRoomStatus::class));
}

it('rechaza marcar reservada u ocupada a mano: esos estados nacen de reservas reales', function () {
    foreach (['reserved' => 'Reservada', 'occupied' => 'Ocupada'] as $status => $label) {
        $response = updateRoomStatus($this->room, $status, $this->user);

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['message'])->toContain('no se marca a mano');
    }

    expect($this->room->refresh()->status->getMorphClass())->toBe('available');
});

it('no libera a mano una reservada con reserva viva: hay que cancelar la reserva', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHours(4),
        'ends_at' => now()->addDay()->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Sofia Jaritzi',
        'guest_phone' => '5511223344',
    ], $this->user);

    // Confirmada con llegada hoy: el semáforo se apartó solo.
    expect($this->room->refresh()->status->getMorphClass())->toBe('reserved');

    $response = updateRoomStatus($this->room, 'available', $this->user);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain($reservation->displayCode())
        ->and($this->room->refresh()->status->getMorphClass())->toBe('reserved');
});

it('sí libera una reservada huérfana (sin reserva que la respalde)', function () {
    $this->room->update(['status' => 'reserved']);

    $response = updateRoomStatus($this->room, 'available', $this->user);

    expect($response->getStatusCode())->toBe(200)
        ->and($this->room->refresh()->status->getMorphClass())->toBe('available');
});

it('las transiciones manuales del payload excluyen reservada y ocupada', function () {
    expect($this->room->refresh()->manualStatusTransitions())->toBe(['maintenance']);

    $this->room->update(['status' => 'dirty']);
    expect($this->room->refresh()->manualStatusTransitions())->toBe(['cleaning', 'maintenance']);

    // El flujo de housekeeping completo sigue funcionando a mano.
    updateRoomStatus($this->room, 'cleaning', $this->user);
    updateRoomStatus($this->room, 'available', $this->user);
    expect($this->room->refresh()->status->getMorphClass())->toBe('available');
});

it('rooms:reserve-arrivals aparta la habitación cuando llega el día de una reserva confirmada', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Pago con tarjeta',
        'guest_phone' => '5599887711',
    ], $this->user);

    // La llegada es mañana: hoy el semáforo no se toca.
    expect($this->room->refresh()->status->getMorphClass())->toBe('available');

    $this->travelTo(now()->addDay()->startOfDay()->addHours(8));
    $this->artisan('rooms:reserve-arrivals')->assertSuccessful();

    $log = RoomStatusLog::query()->where('room_id', $this->room->id)->latest('id')->first();

    expect($this->room->refresh()->status->getMorphClass())->toBe('reserved')
        ->and($log->to_status)->toBe('reserved')
        ->and($log->context['reservation_id'])->toBe($reservation->id)
        ->and($log->context['auto'])->toBeTrue();
});

it('rooms:reserve-arrivals ignora reservas pendientes de pago y habitaciones no disponibles', function () {
    // Pendiente (sin pagar): el semáforo no se aparta.
    app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'guest_name' => 'Sin pagar',
        'guest_phone' => '5599887722',
    ], $this->user);

    $this->travelTo(now()->addDay()->startOfDay()->addHours(8));
    $this->artisan('rooms:reserve-arrivals')->assertSuccessful();
    expect($this->room->refresh()->status->getMorphClass())->toBe('available');

    // Confirmada, pero la habitación está en mantenimiento: se respeta.
    $this->room->reservations()->update(['status' => ReservationStatus::Confirmed]);
    $this->room->update(['status' => 'maintenance']);

    $this->artisan('rooms:reserve-arrivals')->assertSuccessful();
    expect($this->room->refresh()->status->getMorphClass())->toBe('maintenance');
});
