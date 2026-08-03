<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Tenant\RoomBlockController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 800,
    ]);
});

function blocksController(): RoomBlockController
{
    return app(RoomBlockController::class);
}

it('un bloqueo impide crear una reserva solapada', function () {
    RoomBlock::factory()->create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(10)->toDateString(),
        'ends_at' => now()->addDays(12)->toDateString(),
    ]);

    expect(fn () => app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(11)->setTime(15, 0),
        'ends_at' => now()->addDays(13)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Choca Con Bloqueo',
    ]))->toThrow(NoAvailabilityException::class);
});

it('un bloqueo que cubre hoy impide el walk-in', function () {
    RoomBlock::factory()->create([
        'room_id' => $this->room->id,
        'starts_at' => now()->toDateString(),
        'ends_at' => now()->addDay()->toDateString(),
    ]);

    expect(fn () => app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Walk-in Bloqueado',
    ]))->toThrow(NoAvailabilityException::class);
});

it('fuera del rango bloqueado la reserva sí procede', function () {
    RoomBlock::factory()->create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(10)->toDateString(),
        'ends_at' => now()->addDays(12)->toDateString(),
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(5)->setTime(15, 0),
        'ends_at' => now()->addDays(7)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Antes Del Bloqueo',
    ]);

    expect($reservation->exists)->toBeTrue();
});

it('el check-out en la mañana del primer día bloqueado sí se permite', function () {
    // Bloqueo desde el día 12: la noche del 11 al 12 sigue vendible porque
    // el huésped sale antes de que empiece el mantenimiento.
    RoomBlock::factory()->create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(12)->toDateString(),
        'ends_at' => now()->addDays(13)->toDateString(),
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(11)->setTime(15, 0),
        'ends_at' => now()->addDays(12)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Sale Temprano',
    ]);

    expect($reservation->exists)->toBeTrue();
});

it('availableRooms (wizard y panel) excluye la habitación bloqueada y eliminar el bloqueo la restaura', function () {
    $block = RoomBlock::factory()->create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(10)->toDateString(),
        'ends_at' => now()->addDays(12)->toDateString(),
    ]);

    $service = app(AvailabilityService::class);
    $start = now()->addDays(10)->setTime(15, 0);
    $end = now()->addDays(11)->setTime(12, 0);

    expect($service->availableRooms($this->roomType->id, $start, $end)->pluck('id'))
        ->not->toContain($this->room->id);

    // Fuera del bloqueo la habitación sí se ofrece.
    expect($service->availableRooms(
        $this->roomType->id,
        now()->addDays(20)->setTime(15, 0),
        now()->addDays(21)->setTime(12, 0),
    )->pluck('id'))->toContain($this->room->id);

    $block->delete();

    expect($service->availableRooms($this->roomType->id, $start, $end)->pluck('id'))
        ->toContain($this->room->id);
});

it('el endpoint crea el bloqueo y reporta folios de reservas vivas encima', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(12)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Ya Estaba Reservado',
    ]);

    $request = Request::create('/api/rooms/1/blocks', 'POST', [
        'starts_at' => now()->addDays(9)->toDateString(),
        'ends_at' => now()->addDays(13)->toDateString(),
        'reason' => 'Cambio de piso',
    ]);

    $response = blocksController()->store($request, $this->room);
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['reason'])->toBe('Cambio de piso')
        ->and($data['conflicts'])->toHaveCount(1)
        ->and($data['conflicts'][0]['code'])->toBe($reservation->displayCode())
        ->and(RoomBlock::count())->toBe(1);

    // El listado regresa el bloqueo vigente.
    $index = blocksController()->index($this->room)->getData(true);
    expect($index)->toHaveCount(1);
});

it('sin reservas encima el endpoint no reporta conflictos', function () {
    $request = Request::create('/api/rooms/1/blocks', 'POST', [
        'starts_at' => now()->addDays(9)->toDateString(),
        'ends_at' => now()->addDays(13)->toDateString(),
    ]);

    $data = blocksController()->store($request, $this->room)->getData(true);

    expect($data['conflicts'])->toBe([]);
});

it('rechaza un rango con fin antes del inicio', function () {
    $request = Request::create('/api/rooms/1/blocks', 'POST', [
        'starts_at' => now()->addDays(13)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
    ]);

    expect(fn () => blocksController()->store($request, $this->room))
        ->toThrow(ValidationException::class);
});

it('rechaza eliminar un bloqueo de otra habitación', function () {
    $otherRoom = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);
    $block = RoomBlock::factory()->create(['room_id' => $otherRoom->id]);

    expect(fn () => blocksController()->destroy($this->room, $block))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('eliminar el bloqueo por el endpoint restaura la disponibilidad', function () {
    $block = RoomBlock::factory()->create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(10)->toDateString(),
        'ends_at' => now()->addDays(12)->toDateString(),
    ]);

    $response = blocksController()->destroy($this->room, $block);

    expect($response->getStatusCode())->toBe(204)
        ->and(RoomBlock::count())->toBe(0);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(11)->setTime(15, 0),
        'ends_at' => now()->addDays(12)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Ya Sin Bloqueo',
    ]);

    expect($reservation->exists)->toBeTrue();
});
