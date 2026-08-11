<?php

use App\Actions\Rooms\ChangeRoomStatus;
use App\Actions\Rooms\SyncRoomUsageLock;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\Event;

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
});

/** Simula un ciclo de uso completo: walk-in ocupa y el check-out ensucia. */
function useRoomOnce(Room $room): Room
{
    $action = app(ChangeRoomStatus::class);
    $action->handle($room, RoomStatus::Occupied->value);
    $action->handle($room, RoomStatus::Dirty->value);

    return $room->refresh();
}

it('incrementa el contador cuando la habitación queda por limpiar tras usarse', function () {
    expect((int) $this->room->usage_count)->toBe(0);

    useRoomOnce($this->room);

    expect((int) $this->room->usage_count)->toBe(1)
        ->and($this->room->usageLocked())->toBeFalse();
});

it('no cuenta transiciones que no son un uso', function () {
    app(ChangeRoomStatus::class)->handle($this->room, RoomStatus::Maintenance->value);
    app(ChangeRoomStatus::class)->handle($this->room->refresh(), RoomStatus::Available->value);

    expect((int) $this->room->refresh()->usage_count)->toBe(0);
});

it('aplica el candado al llegar al límite cuando hay hermanas en servicio', function () {
    $sibling = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);

    $this->room->update(['usage_limit' => 1]);

    useRoomOnce($this->room->refresh());

    expect($this->room->usageLocked())->toBeTrue()
        ->and($sibling->refresh()->usageLocked())->toBeFalse();

    // Bloqueada por usos: fuera de disponibilidad aunque el semáforo avance.
    $availability = app(AvailabilityService::class);
    $rooms = $availability->availableRooms($this->roomType->id, now()->addDay(), now()->addDays(2));

    expect($rooms->pluck('id')->all())->toBe([$sibling->id])
        ->and($availability->isRoomAvailable($this->room, now()->addDay(), now()->addDays(2)))->toBeFalse();
});

it('no aplica el candado si es la última habitación del tipo en servicio', function () {
    $this->room->update(['usage_limit' => 1]);

    useRoomOnce($this->room->refresh());

    expect((int) $this->room->usage_count)->toBe(1)
        ->and($this->room->usageLocked())->toBeFalse();
});

it('la asignación automática prefiere la habitación con menos usos', function () {
    $fresh = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);

    $this->room->update(['usage_count' => 5]);

    $rooms = app(AvailabilityService::class)
        ->availableRooms($this->roomType->id, now()->addDay(), now()->addDays(2));

    // La 102 (0 usos) va antes que la 101 (5 usos) pese al número mayor.
    expect($rooms->pluck('id')->all())->toBe([$fresh->id, $this->room->id]);
});

it('el reset regresa el contador a cero y retira el candado', function () {
    Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);

    $this->room->update(['usage_limit' => 1]);
    useRoomOnce($this->room->refresh());
    expect($this->room->usageLocked())->toBeTrue();

    app(SyncRoomUsageLock::class)->reset($this->room);

    expect((int) $this->room->usage_count)->toBe(0)
        ->and($this->room->usageLocked())->toBeFalse();
});

it('editar contador o límite a mano sincroniza el candado en ambos sentidos', function () {
    Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);

    // Subir el conteo por encima del límite bloquea.
    $this->room->update(['usage_limit' => 5, 'usage_count' => 10]);
    app(SyncRoomUsageLock::class)->handle($this->room);
    expect($this->room->usageLocked())->toBeTrue();

    // Quitar el límite desbloquea.
    $this->room->update(['usage_limit' => null]);
    app(SyncRoomUsageLock::class)->handle($this->room->refresh());
    expect($this->room->usageLocked())->toBeFalse();
});
