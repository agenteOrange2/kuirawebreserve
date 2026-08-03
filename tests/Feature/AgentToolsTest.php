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

function agentHold(RatePlan $plan, int $daysAhead = 2): \App\Models\Reservation
{
    return app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays($daysAhead)->setTime(15, 0),
        'confirmed' => false,
        'source_channel' => 'agent',
        'guest_name' => 'Ana García',
    ]);
}

it('create_hold expone payment_options con los métodos reales del hotel', function () {
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
        'cash_payment_enabled' => true,
    ]]);
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'), 'provider' => 'paypal', 'mode' => 'test',
        'public_key' => 'client', 'secret_key' => 'secret',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $request = Request::create('/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addHour()->toIso8601String(),
        'guest_name' => 'Ana García',
    ]);
    $data = app(AgentToolsController::class)->storeHold($request, app(\App\Actions\Reservations\CreateReservation::class))->getData(true);

    expect($data['payment_options']['pasarelas'][0]['provider'])->toBe('paypal')
        ->and($data['payment_options']['transferencia'])->toBeTrue()
        ->and($data['payment_options']['efectivo'])->toBeTrue();
});

it('solicitar_pago respeta la elección de transferencia aunque haya pasarela conectada', function () {
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
    ]]);
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'), 'provider' => 'stripe', 'mode' => 'test',
        'public_key' => 'pk', 'secret_key' => 'sk',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $reservation = agentHold($plan);

    $response = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $reservation->code, 'metodo' => 'transferencia']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['method'])->toBe('transferencia')
        ->and($data['bank_accounts'])->toHaveCount(1);
});

it('solicitar_pago con efectivo extiende el apartado; con efectivo apagado explica el error', function () {
    $this->property->update(['settings' => ['cash_payment_enabled' => true]]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $reservation = agentHold($plan);
    $holdBefore = $reservation->hold_expires_at;

    $response = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $reservation->code, 'metodo' => 'efectivo']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['method'])->toBe('efectivo')
        ->and($reservation->fresh()->hold_expires_at->gt($holdBefore))->toBeTrue()
        ->and($reservation->fresh()->notes)->toContain('Eligió pagar en el hotel');

    // Hotel sin efectivo: el bot recibe un mensaje claro para reofrecer.
    $this->property->update(['settings' => ['cash_payment_enabled' => false]]);
    $otra = agentHold($plan, daysAhead: 5);

    $denied = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $otra->code, 'metodo' => 'efectivo']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );

    expect($denied->getStatusCode())->toBe(422);
});

it('solicitar_pago con pasarela elegida pero no conectada explica el error sin sustituir en silencio', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $reservation = agentHold($plan);

    $response = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $reservation->code, 'metodo' => 'pasarela']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('pasarela');
});
