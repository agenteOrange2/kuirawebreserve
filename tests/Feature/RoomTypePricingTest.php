<?php

use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla']);
});

it('deriva el precio desde de la tarifa activa más barata', function () {
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 900,
    ]);
    RatePlan::factory()->block(180, 350)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    // Las inactivas no cuentan aunque sean más baratas.
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 100,
        'active' => false,
    ]);

    expect($this->roomType->priceFrom())->toBe(350.0)
        ->and($this->roomType->hasActiveRate())->toBeTrue();
});

it('sin tarifa activa el tipo no es reservable (guarda)', function () {
    expect($this->roomType->priceFrom())->toBeNull()
        ->and($this->roomType->hasActiveRate())->toBeFalse();

    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
        'active' => false,
    ]);

    expect($this->roomType->fresh()->hasActiveRate())->toBeFalse();
});

it('priceFrom usa el alias de withMin cuando la query lo trae', function () {
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 650,
    ]);

    $loaded = RoomType::query()
        ->withMin(['ratePlans as price_from' => fn ($q) => $q->where('active', true)], 'price')
        ->findOrFail($this->roomType->id);

    expect($loaded->priceFrom())->toBe(650.0);
});

it('duplica el tipo con sus tarifas', function () {
    $this->roomType->update(['amenities' => ['tv', 'wifi'], 'capacity' => 3]);

    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'name' => 'Noche',
        'price' => 900,
    ]);
    RatePlan::factory()->block(180, 350)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'name' => 'Rato 3 horas',
    ]);

    $copy = $this->roomType->fresh()->duplicateWithRatePlans();

    expect($copy->name)->toBe('Sencilla (copia)')
        ->and($copy->capacity)->toBe(3)
        ->and($copy->amenities)->toBe(['tv', 'wifi'])
        ->and($copy->ratePlans()->count())->toBe(2)
        ->and($copy->ratePlans()->pluck('name')->sort()->values()->all())->toBe(['Noche', 'Rato 3 horas'])
        ->and($copy->priceFrom())->toBe(350.0)
        // Las tarifas del original no se movieron.
        ->and($this->roomType->ratePlans()->count())->toBe(2);
});

it('el backfill de la migración crea Tarifa base solo para tipos sin tarifas', function () {
    // Estado previo a la migración: un tipo con tarifa, otro sin tarifa con
    // base_price, y otro sin tarifa con base_price 0 (no debe venderse gratis).
    $conTarifa = RoomType::factory()->create(['property_id' => $this->property->id, 'base_price' => 1300]);
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $conTarifa->id,
        'name' => 'Precio base',
        'price' => 1300,
    ]);

    $sinTarifa = RoomType::factory()->create(['property_id' => $this->property->id, 'base_price' => 850]);
    $precioCero = RoomType::factory()->create(['property_id' => $this->property->id, 'base_price' => 0]);
    // $this->roomType (del beforeEach) también queda sin tarifas y su
    // base_price aleatorio de factory > 0 recibirá tarifa.

    $migration = require database_path('migrations/tenant/2026_07_15_100001_seed_base_rate_plans_from_base_price.php');
    $migration->up();

    expect($conTarifa->ratePlans()->count())->toBe(1) // no se duplicó
        ->and($sinTarifa->refresh()->priceFrom())->toBe(850.0)
        ->and($sinTarifa->ratePlans()->first()->name)->toBe('Tarifa base')
        ->and($sinTarifa->ratePlans()->first()->type->value)->toBe('night')
        ->and($precioCero->ratePlans()->count())->toBe(0)
        ->and($precioCero->fresh()->hasActiveRate())->toBeFalse();
});
