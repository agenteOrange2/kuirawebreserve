<?php

use App\Http\Controllers\Tenant\AvailabilityController;
use App\Http\Controllers\Tenant\BookingController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

/*
 * Descuento por semana/mes (temporadas promo con min_nights, como Hotel
 * México: Doble $750 → $600 la noche desde 30 noches). El servidor ya lo
 * cobraba; el wizard no enseñaba el precio normal y el panel estimaba con
 * precio base × noches ($22,500 donde se cobran $18,000).
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Doble', 'capacity' => 4]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 750,
    ]);

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id, 'name' => 'Por semana (15% de descuento)', 'kind' => 'promo',
        'starts_on' => null, 'ends_on' => null, 'min_nights' => 7, 'price' => 637.50, 'priority' => 1, 'active' => true,
    ]);
    RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id, 'name' => 'Por mes (20% de descuento)', 'kind' => 'promo',
        'starts_on' => null, 'ends_on' => null, 'min_nights' => 30, 'price' => 600, 'priority' => 2, 'active' => true,
    ]);
});

it('la línea con descuento trae el precio normal para tacharlo', function () {
    $start = now()->addDays(5)->setTime(15, 0);
    $end = $start->copy()->addDays(30)->setTime(12, 0);

    $line = $this->plan->priceBreakdown($start, $end, null)[0];

    expect($line['amount'])->toEqual(18000.0)
        ->and($line['regular_amount'])->toEqual(22500.0)
        ->and($line['unit_price'])->toEqual(600.0)
        ->and($line['regular_unit_price'])->toEqual(750.0)
        ->and($line['units'])->toBe(30);
});

it('sin descuento la línea no trae campos de precio normal', function () {
    $start = now()->addDays(5)->setTime(15, 0);
    $end = $start->copy()->addDays(2)->setTime(12, 0);

    expect($this->plan->priceBreakdown($start, $end, null)[0])->not->toHaveKey('regular_amount');
});

it('ofrece el siguiente descuento que la estancia aún no alcanza', function () {
    $start = now()->addDays(5)->setTime(15, 0);

    expect($this->plan->longStayOffers($start, 3))->toBe([
        ['min_nights' => 7, 'unit_price' => 637.5, 'percent' => 15, 'name' => 'Por semana (15% de descuento)'],
        ['min_nights' => 30, 'unit_price' => 600.0, 'percent' => 20, 'name' => 'Por mes (20% de descuento)'],
    ])
        ->and($this->plan->longStayOffers($start, 10))->toHaveCount(1)
        ->and($this->plan->longStayOffers($start, 30))->toBe([]);
});

it('el wizard público manda el descuento, la oferta y la ficha de persona extra', function () {
    $this->room->update(['included_occupancy' => 2, 'extra_guest_fee' => 50]);
    $arrive = now()->addDays(5)->toDateString();

    $request = Request::create('/api/booking/availability', 'GET', [
        'mode' => 'night',
        'arrive_date' => $arrive,
        'depart_date' => now()->addDays(10)->toDateString(),
    ]);
    $option = app(BookingController::class)->availability($request, app(AvailabilityService::class))->getData(true)['options'][0];

    expect($option['total'])->toEqual(3750.0)
        ->and($option['long_stay_offers'][0]['min_nights'])->toBe(7)
        ->and($option['included_occupancy'])->toBe(2)
        ->and($option['extra_guest_fee'])->toEqual(50.0)
        ->and($option['units'])->toBe(5);
});

it('la disponibilidad del panel cotiza con la temporada, no con precio base × noches', function () {
    $start = now()->addDays(5)->setTime(15, 0);
    $this->room->update(['price_modifier' => -20]);

    $request = Request::create('/api/availability', 'GET', [
        'rate_plan_id' => $this->plan->id,
        'starts_at' => $start->toIso8601String(),
        'ends_at' => $start->copy()->addDays(30)->setTime(12, 0)->toIso8601String(),
    ]);
    $data = app(AvailabilityController::class)($request, app(AvailabilityService::class))->getData(true);

    expect($data['total'])->toEqual(18000.0)
        ->and($data['regular_total'])->toEqual(22500.0)
        ->and($data['price_breakdown'][0]['regular_amount'])->toEqual(22500.0)
        // El cuarto con −$20 por noche: 30 × 580.
        ->and($data['rooms'][0]['base_total'])->toEqual(17400.0);
});
