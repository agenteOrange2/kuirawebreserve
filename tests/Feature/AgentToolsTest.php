<?php

use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 3]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'included_occupancy' => 2,
        'extra_guest_fee' => 650,
    ]);
});

it('create_hold devuelve price_breakdown con la misma lógica que el wizard público (spec-wizard-precios §P2)', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $request = Request::create('/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addHour()->toIso8601String(),
        'guest_name' => 'Ana García',
        'adults' => 3,
    ]);

    $response = app(AgentToolsController::class)->storeHold($request, app(\App\Actions\Reservations\CreateReservation::class));
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['total'])->toEqual(1550.0) // 900 + 650 por la 3a persona
        ->and($data['price_breakdown'])->toHaveCount(2)
        ->and($data['price_breakdown'][1]['concept'])->toContain('Personas extra')
        ->and($data['price_breakdown'][1]['amount'])->toEqual(650.0)
        ->and($data['price_breakdown'][1]['amount_label'])->toBe('$650.00');
});

it('create_hold sin cargos extra devuelve una sola línea de tarifa', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $request = Request::create('/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addHour()->toIso8601String(),
        'guest_name' => 'Ana García',
        'adults' => 1,
    ]);

    $response = app(AgentToolsController::class)->storeHold($request, app(\App\Actions\Reservations\CreateReservation::class));
    $data = $response->getData(true);

    expect($data['price_breakdown'])->toHaveCount(1)
        ->and($data['price_breakdown'][0]['amount'])->toEqual(900.0);
});
