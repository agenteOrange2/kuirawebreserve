<?php

use App\Http\Controllers\Tenant\RatePlanSeasonController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
});

function seasonsController(): RatePlanSeasonController
{
    return app(RatePlanSeasonController::class);
}

it('crea una temporada y la lista de vuelta', function () {
    $request = Request::create('/api/rate-plans/1/seasons', 'POST', [
        'name' => 'Semana Santa',
        'starts_on' => '2026-04-01',
        'ends_on' => '2026-04-10',
        'price' => 1500,
        'priority' => 5,
    ]);

    $response = seasonsController()->store($request, $this->plan);
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['name'])->toBe('Semana Santa')
        ->and($data['kind'])->toBe('season')
        ->and((float) $data['price'])->toBe(1500.0)
        ->and($data['priority'])->toBe(5);

    $index = seasonsController()->index($this->plan)->getData(true);
    expect($index)->toHaveCount(1);
});

it('rechaza ends_on antes de starts_on', function () {
    $request = Request::create('/api/rate-plans/1/seasons', 'POST', [
        'name' => 'Inválida',
        'starts_on' => '2026-04-10',
        'ends_on' => '2026-04-01',
        'price' => 1000,
    ]);

    expect(fn () => seasonsController()->store($request, $this->plan))->toThrow(ValidationException::class);
});

it('actualiza una temporada existente', function () {
    $season = RatePlanSeason::factory()->create(['rate_plan_id' => $this->plan->id, 'price' => 1000]);

    $request = Request::create("/api/rate-plans/{$this->plan->id}/seasons/{$season->id}", 'PATCH', ['price' => 1300]);
    $response = seasonsController()->update($request, $this->plan, $season);

    expect($response->getStatusCode())->toBe(200)
        ->and((float) $response->getData(true)['price'])->toBe(1300.0);
});

it('elimina una temporada', function () {
    $season = RatePlanSeason::factory()->create(['rate_plan_id' => $this->plan->id]);

    $response = seasonsController()->destroy($this->plan, $season);

    expect($response->getStatusCode())->toBe(204)
        ->and(RatePlanSeason::count())->toBe(0);
});

it('rechaza editar/eliminar una temporada que pertenece a otra tarifa', function () {
    $otherPlan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $season = RatePlanSeason::factory()->create(['rate_plan_id' => $otherPlan->id]);

    $request = Request::create('/x', 'PATCH', ['price' => 999]);

    expect(fn () => seasonsController()->update($request, $this->plan, $season))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    expect(fn () => seasonsController()->destroy($this->plan, $season))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});
