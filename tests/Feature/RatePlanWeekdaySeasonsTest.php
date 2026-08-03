<?php

use App\Http\Controllers\Tenant\RatePlanSeasonController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use App\Models\RoomType;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 800,
    ]);
});

function weekdaySeasonsController(): RatePlanSeasonController
{
    return app(RatePlanSeasonController::class);
}

it('dentro del mismo stay, la noche de viernes usa el precio de temporada y la de miércoles el base', function () {
    // Estancia miércoles → sábado (noches: mié, jue, vie) con una temporada
    // de fin de semana [5=viernes, 6=sábado] que cubre todo el rango.
    $wednesday = now()->addMonth()->next(CarbonInterface::WEDNESDAY)->setTime(15, 0);
    $saturday = $wednesday->copy()->addDays(3)->setTime(12, 0);

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id,
        'name' => 'Fin de semana',
        'starts_on' => $wednesday->copy()->subWeek()->toDateString(),
        'ends_on' => $saturday->copy()->addWeek()->toDateString(),
        'weekdays' => [5, 6],
        'price' => 1200,
    ]);

    // mié 800 + jue 800 + vie 1200 = 2800
    expect($this->plan->priceFor($wednesday, $saturday))->toEqual(2800.0);

    $breakdown = $this->plan->priceBreakdown($wednesday, $saturday, null);
    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0]['concept'])->toBe('Tarifa (2 noches)')
        ->and($breakdown[0]['amount'])->toEqual(1600.0)
        ->and($breakdown[1]['concept'])->toBe('Fin de semana (1 noche)')
        ->and($breakdown[1]['amount'])->toEqual(1200.0);
});

it('una regla sin fechas con weekdays aplica en cualquier semana del año', function () {
    RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id,
        'name' => 'Viernes y sábado',
        'starts_on' => null,
        'ends_on' => null,
        'weekdays' => [5, 6],
        'price' => 1500,
    ]);

    $fridayNear = now()->addWeek()->next(CarbonInterface::FRIDAY)->setTime(15, 0);
    $fridayFar = now()->addMonths(7)->next(CarbonInterface::FRIDAY)->setTime(15, 0);
    $tuesday = now()->addWeek()->next(CarbonInterface::TUESDAY)->setTime(15, 0);

    expect($this->plan->priceFor($fridayNear, $fridayNear->copy()->addDay()->setTime(12, 0)))->toEqual(1500.0)
        ->and($this->plan->priceFor($fridayFar, $fridayFar->copy()->addDay()->setTime(12, 0)))->toEqual(1500.0)
        ->and($this->plan->priceFor($tuesday, $tuesday->copy()->addDay()->setTime(12, 0)))->toEqual(800.0);
});

it('sin weekdays la temporada se comporta igual que antes (regresión)', function () {
    $start = now()->addDays(10)->setTime(15, 0);
    $end = $start->copy()->addDays(2)->setTime(12, 0);

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id,
        'starts_on' => $start->copy()->subDay()->toDateString(),
        'ends_on' => $end->copy()->addDay()->toDateString(),
        'weekdays' => null,
        'price' => 1000,
    ]);

    // Aplica TODAS las noches del rango, caiga el día que caiga.
    expect($this->plan->priceFor($start, $end))->toEqual(2000.0);
});

it('en tarifas por bloque el weekday se resuelve con el día de inicio', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    RatePlanSeason::factory()->create([
        'rate_plan_id' => $plan->id,
        'starts_on' => null,
        'ends_on' => null,
        'weekdays' => [6],
        'price' => 700,
    ]);

    $saturday = now()->addWeek()->next(CarbonInterface::SATURDAY)->setTime(15, 0);
    $monday = now()->addWeek()->next(CarbonInterface::MONDAY)->setTime(15, 0);

    expect($plan->priceFor($saturday, $saturday->copy()->addHours(12)))->toEqual(700.0)
        ->and($plan->priceFor($monday, $monday->copy()->addHours(12)))->toEqual(900.0);
});

it('el endpoint acepta una temporada sin fechas con weekdays y la serializa', function () {
    $request = Request::create('/api/rate-plans/1/seasons', 'POST', [
        'name' => 'Todos los viernes',
        'weekdays' => [5],
        'price' => 1400,
    ]);

    $response = weekdaySeasonsController()->store($request, $this->plan);
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['starts_on'])->toBeNull()
        ->and($data['ends_on'])->toBeNull()
        ->and($data['weekdays'])->toBe([5]);
});

it('el endpoint rechaza una temporada sin fechas y sin weekdays', function () {
    $request = Request::create('/api/rate-plans/1/seasons', 'POST', [
        'name' => 'Sin regla',
        'price' => 1400,
    ]);

    expect(fn () => weekdaySeasonsController()->store($request, $this->plan))
        ->toThrow(ValidationException::class);
});

it('el endpoint rechaza weekdays fuera de 0-6 y fechas incompletas', function () {
    $badDay = Request::create('/api/rate-plans/1/seasons', 'POST', [
        'name' => 'Día inválido',
        'weekdays' => [7],
        'price' => 1400,
    ]);
    expect(fn () => weekdaySeasonsController()->store($badDay, $this->plan))
        ->toThrow(ValidationException::class);

    $halfRange = Request::create('/api/rate-plans/1/seasons', 'POST', [
        'name' => 'Media fecha',
        'starts_on' => now()->addDays(5)->toDateString(),
        'price' => 1400,
    ]);
    expect(fn () => weekdaySeasonsController()->store($halfRange, $this->plan))
        ->toThrow(ValidationException::class);
});

it('en update se puede quitar el rango dejando los weekdays', function () {
    $season = RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id,
        'weekdays' => [5, 6],
        'price' => 1200,
    ]);

    $request = Request::create('/x', 'PATCH', ['starts_on' => null, 'ends_on' => null]);
    $response = weekdaySeasonsController()->update($request, $this->plan, $season);
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['starts_on'])->toBeNull()
        ->and($data['weekdays'])->toBe([5, 6]);
});

it('en update no se pueden quitar los weekdays si tampoco hay fechas', function () {
    $season = RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id,
        'starts_on' => null,
        'ends_on' => null,
        'weekdays' => [5],
        'price' => 1200,
    ]);

    $request = Request::create('/x', 'PATCH', ['weekdays' => null]);

    expect(fn () => weekdaySeasonsController()->update($request, $this->plan, $season))
        ->toThrow(ValidationException::class);
});
