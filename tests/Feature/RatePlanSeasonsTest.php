<?php

use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use App\Models\Room;
use App\Models\RoomType;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
});

it('sin temporadas, priceFor no cambia (compat)', function () {
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 800]);

    $total = $plan->priceFor(now()->addDay()->setTime(15, 0), now()->addDays(3)->setTime(12, 0));

    expect($total)->toEqual(1600.0); // 2 noches × 800
});

it('una temporada que cubre todo el rango sustituye el precio de esas noches', function () {
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 800]);
    $start = now()->addDays(10)->setTime(15, 0);
    $end = now()->addDays(12)->setTime(12, 0); // 2 noches

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $plan->id,
        'name' => 'Temporada alta',
        'starts_on' => $start->copy()->subDays(5),
        'ends_on' => $end->copy()->addDays(5),
        'price' => 1200,
    ]);

    expect($plan->priceFor($start, $end))->toEqual(2400.0); // 2 noches × 1200
});

it('una temporada que cubre solo parte del rango mezcla precio base y de temporada', function () {
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 800]);
    $start = now()->addDays(10)->startOfDay()->setTime(15, 0);
    $end = $start->copy()->addDays(4)->setTime(12, 0); // 4 noches: día 0,1,2,3

    // La temporada solo cubre los dos últimos días de la estancia.
    RatePlanSeason::factory()->create([
        'rate_plan_id' => $plan->id,
        'name' => 'Fin de semana largo',
        'starts_on' => $start->copy()->addDays(2)->toDateString(),
        'ends_on' => $start->copy()->addDays(3)->toDateString(),
        'price' => 1200,
    ]);

    // 2 noches base (800×2) + 2 noches temporada (1200×2) = 4000
    expect($plan->priceFor($start, $end))->toEqual(4000.0);

    $breakdown = $plan->priceBreakdown($start, $end, null);
    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0]['concept'])->toBe('Tarifa (2 noches)')
        ->and($breakdown[0]['amount'])->toEqual(1600.0)
        ->and($breakdown[1]['concept'])->toBe('Fin de semana largo (2 noches)')
        ->and($breakdown[1]['amount'])->toEqual(2400.0)
        ->and(array_sum(array_column($breakdown, 'amount')))->toEqual(4000.0);
});

it('con temporadas solapadas gana la de mayor prioridad', function () {
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 800]);
    $date = now()->addDays(10);

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $plan->id,
        'name' => 'Temporada alta',
        'starts_on' => $date->copy()->subDays(5),
        'ends_on' => $date->copy()->addDays(5),
        'price' => 1200,
        'priority' => 1,
    ]);
    RatePlanSeason::factory()->create([
        'rate_plan_id' => $plan->id,
        'name' => 'Promo relámpago',
        'starts_on' => $date->copy()->subDay(),
        'ends_on' => $date->copy()->addDay(),
        'price' => 500,
        'priority' => 10,
    ]);

    $season = $plan->activeSeasonFor($date);

    expect($season->name)->toBe('Promo relámpago');
});

it('una temporada inactiva no aplica', function () {
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 800]);
    $date = now()->addDays(10);

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $plan->id,
        'starts_on' => $date->copy()->subDays(5),
        'ends_on' => $date->copy()->addDays(5),
        'price' => 1200,
        'active' => false,
    ]);

    expect($plan->activeSeasonFor($date))->toBeNull();
});

it('en modo block, la temporada se resuelve por el día de inicio', function () {
    $plan = RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $start = now()->addDays(10)->setTime(15, 0);
    $end = $start->copy()->addHours(12);

    RatePlanSeason::factory()->promo()->create([
        'rate_plan_id' => $plan->id,
        'starts_on' => $start->copy()->subDay(),
        'ends_on' => $start->copy()->addDay(),
        'price' => 700,
    ]);

    expect($plan->priceFor($start, $end))->toEqual(700.0);

    $breakdown = $plan->priceBreakdown($start, $end, null);
    expect($breakdown[0]['concept'])->toContain('Promo lanzamiento');
});

it('el ajuste del cuarto se suma por unidad encima del precio de temporada', function () {
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 800]);
    $start = now()->addDays(10)->setTime(15, 0);
    $end = $start->copy()->addDays(2)->setTime(12, 0); // 2 noches

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $plan->id,
        'starts_on' => $start->copy()->subDay(),
        'ends_on' => $end->copy()->addDay(),
        'price' => 1000,
    ]);
    $this->room->update(['price_modifier' => 100]);

    // 2 noches × (1000 + 100) = 2200
    expect($plan->priceFor($start, $end, $this->room))->toEqual(2200.0);
});
