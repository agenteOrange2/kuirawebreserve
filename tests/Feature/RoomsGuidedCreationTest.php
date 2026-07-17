<?php

use App\Actions\Catalog\CreateRoomTypeWithBaseRate;
use App\Actions\Rooms\CreateRoomRange;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Zone;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
});

it('crea un rango de habitaciones omitiendo los números que ya existen', function () {
    $zone = Zone::factory()->create(['property_id' => $this->property->id]);

    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'number' => '103']);

    $result = app(CreateRoomRange::class)->execute($this->property->id, $this->roomType->id, $zone->id, 101, 105);

    expect($result['created'])->toBe(['101', '102', '104', '105'])
        ->and($result['skipped'])->toBe(['103'])
        ->and(Room::query()->count())->toBe(5)
        ->and(Room::query()->where('number', '104')->first()->zone_id)->toBe($zone->id)
        ->and(Room::query()->where('number', '104')->first()->status->getMorphClass())->toBe('available');

    // Las posiciones del plano no se enciman (escalonadas).
    $positions = Room::query()->whereIn('number', $result['created'])->get(['pos_x', 'pos_y'])
        ->map(fn (Room $room) => "{$room->pos_x},{$room->pos_y}");
    expect($positions->unique())->toHaveCount(4);
});

it('calcula el siguiente número libre saltando ocupados', function () {
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'number' => '204']);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'number' => '205']);

    expect(Room::nextAvailableNumber('204', $this->property->id))->toBe('206')
        ->and(Room::nextAvailableNumber('300', $this->property->id))->toBe('301')
        ->and(Room::nextAvailableNumber('Suite A', $this->property->id))->toBe('Suite A-2');
});

it('duplica la habitación con su ficha pero nace disponible y sin notas', function () {
    $original = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'view' => 'jardín',
        'amenities' => ['tv', 'minibar'],
        'price_modifier' => 100,
        'smoking' => true,
        'status' => 'dirty',
        'notes' => 'TV pendiente',
    ]);

    $copy = $original->duplicateAsNew();

    expect($copy->number)->toBe('102')
        ->and($copy->view)->toBe('jardín')
        ->and($copy->amenities)->toBe(['tv', 'minibar'])
        ->and((float) $copy->price_modifier)->toBe(100.0)
        ->and($copy->smoking)->toBeTrue()
        ->and($copy->status->getMorphClass())->toBe('available')
        ->and($copy->notes)->toBeNull()
        ->and($copy->room_type_id)->toBe($original->room_type_id);
});

it('crea tipo con su tarifa base por noche o por bloque', function () {
    $action = app(CreateRoomTypeWithBaseRate::class);

    $noche = $action->execute(
        ['property_id' => $this->property->id, 'name' => 'Suite', 'capacity' => 2],
        ['price' => 900, 'rate_type' => 'night'],
    );

    expect($noche->priceFrom())->toBe(900.0)
        ->and($noche->ratePlans()->first()->name)->toBe('Tarifa base')
        ->and($noche->ratePlans()->first()->type->value)->toBe('night');

    $bloque = $action->execute(
        ['property_id' => $this->property->id, 'name' => 'Rato', 'capacity' => 2],
        ['price' => 350, 'rate_type' => 'block', 'duration_unit' => 'hour', 'duration_value' => 3],
    );

    $plan = $bloque->ratePlans()->first();

    expect($plan->type->value)->toBe('block')
        ->and($plan->duration_value)->toBe(3)
        ->and($plan->duration_minutes)->toBe(180)
        ->and($plan->durationLabel())->toBe('3 horas');
});
