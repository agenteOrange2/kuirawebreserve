<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/*
 * Tarifas de solo recepción (rate_plans.online=false). Caso Hotel México
 * 2026-09-23: la de 1 hora se vende en mostrador, pero el asistente no debe
 * cotizarla, apartarla ni mandar liga de pago, y el wizard no la muestra.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla', 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    $this->hourPlan = RatePlan::factory()->block(60, 290)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'name' => '1 hora',
        'online' => false,
    ]);
    $this->twoHourPlan = RatePlan::factory()->block(120, 330)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'name' => '2 horas',
    ]);
});

it('las tarifas nuevas se venden en línea por default', function () {
    expect($this->twoHourPlan->fresh()->online)->toBeTrue()
        ->and(RatePlan::query()->sellableOnline()->pluck('id')->all())->toBe([$this->twoHourPlan->id]);
});

it('get_rate_plans no le enseña al asistente la tarifa de solo recepción', function () {
    $plans = collect(app(AgentToolsController::class)->ratePlans()->getData(true)['rate_plans']);

    expect($plans->pluck('id')->all())->toBe([$this->twoHourPlan->id]);
});

it('la disponibilidad del asistente cotiza con la tarifa en línea más barata, no con la de recepción', function () {
    $request = Request::create('/agent/availability-overview', 'GET', [
        'starts_at' => now()->addDay()->setTime(12, 0)->toIso8601String(),
    ]);

    $payload = app(AgentToolsController::class)->availabilityOverview($request, app(AvailabilityService::class))->getData(true);
    $option = collect($payload['options'])->firstWhere('room_type', 'Sencilla');

    expect($option['rate_plan_id'])->toBe($this->twoHourPlan->id)
        ->and($option['total'])->toEqual(330.0);
});

it('el asistente no puede cotizar ni apartar la tarifa de solo recepción aunque traiga su id', function () {
    $params = [
        'rate_plan_id' => $this->hourPlan->id,
        'starts_at' => now()->addHour()->toIso8601String(),
        'guest_name' => 'Ana García',
    ];

    $quote = app(AgentToolsController::class)->availability(Request::create('/agent/availability', 'GET', $params), app(AvailabilityService::class));
    $hold = app(AgentToolsController::class)->storeHold(Request::create('/agent/holds', 'POST', $params), app(CreateReservation::class));

    expect($quote->getStatusCode())->toBe(422)
        ->and($quote->getData(true)['message'])->toContain('recepción')
        ->and($hold->getStatusCode())->toBe(422)
        ->and(\App\Models\Reservation::count())->toBe(0);
});

it('el wizard cotiza con la tarifa en línea', function () {
    $request = Request::create('/api/booking/availability', 'GET', [
        'mode' => 'block',
        'arrive_at' => now()->addHour()->toIso8601String(),
        'adults' => 1,
    ]);

    $payload = app(BookingController::class)->availability($request, app(AvailabilityService::class))->getData(true);

    expect($payload['options'])->toHaveCount(1)
        ->and($payload['options'][0]['total'])->toEqual(330.0);
});

it('en recepción la tarifa de 1 hora sí se cobra', function () {
    $request = Request::create('/api/stays', 'POST', [
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->hourPlan->id,
        'payment_method' => 'cash',
    ]);
    $request->setUserResolver(fn () => null);

    $response = app(StayController::class)->store($request, app(CreateWalkInStay::class));

    expect($response->getStatusCode())->toBe(201)
        ->and((float) \App\Models\Stay::firstOrFail()->amount)->toEqual(290.0);
});
