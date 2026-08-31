<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Formas de cobro que acepta la recepción (/ajustes/metodos-pago →
 * Políticas). Es OTRA COSA que los métodos en línea de /admin
 * (PaymentMethodGate): aquellos son lo que se le ofrece al huésped en el
 * wizard público; esto es la caja y la terminal del mostrador. Un hotel puede
 * tener terminal sin ninguna pasarela, o al revés.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '801',
    ]);
    $this->plan = RatePlan::factory()->block(720, 1300)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function acceptOnly(array $methods): void
{
    $property = Property::firstOrFail();
    $property->update([
        'settings' => array_merge($property->settings ?? [], ['counter_methods' => $methods]),
    ]);
    app()->forgetInstance(ReservationPolicy::class);
}

function registerWalkIn(array $payload)
{
    $request = Request::create('/api/stays', 'POST', array_merge([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
    ], $payload));
    $request->setUserResolver(fn () => null);

    return app(StayController::class)->store($request, app(CreateWalkInStay::class));
}

it('sin ajuste guardado acepta las tres: el mostrador opera como siempre', function () {
    expect(app(ReservationPolicy::class)->counterMethods())
        ->toBe(['cash', 'card', 'transfer']);
});

it('el hotel que no tiene terminal deja de aceptar cobros con tarjeta', function () {
    acceptOnly(['cash', 'transfer']);

    expect(app(ReservationPolicy::class)->counterMethods())->toBe(['cash', 'transfer'])
        ->and(app(ReservationPolicy::class)->counterMethodEnabled('card'))->toBeFalse();

    registerWalkIn(['payment_method' => 'card']);
})->throws(ValidationException::class);

it('lo que sí acepta pasa igual que antes', function () {
    acceptOnly(['cash', 'transfer']);

    $response = registerWalkIn(['payment_method' => 'transfer', 'guest_name' => 'Paga Transferencia']);

    expect($response->getStatusCode())->toBe(201);

    $stay = \App\Models\Stay::latest('id')->firstOrFail();
    expect($stay->payments()->first()->method)->toBe('transfer');
});

it('apagarlas todas no deja al mostrador sin cobrar: queda el efectivo', function () {
    acceptOnly([]);

    expect(app(ReservationPolicy::class)->counterMethods())->toBe(['cash']);
});

it('la fianza solo admite lo que se recibe en la mano, y solo si se acepta', function () {
    acceptOnly(['cash', 'transfer']);

    // Transferencia sí es método del mostrador, pero la fianza no la admite.
    expect(fn () => registerWalkIn(['guarantee_method' => 'transfer']))
        ->toThrow(ValidationException::class)
        // Y la terminal, que la fianza sí admite, está apagada en este hotel.
        ->and(fn () => registerWalkIn(['guarantee_method' => 'card']))
        ->toThrow(ValidationException::class);
});

it('el panel comparte la lista para que ninguna pantalla ofrezca de más', function () {
    acceptOnly(['cash']);

    // Es el mismo origen que lee useCounterMethods() en el front.
    expect(app(ReservationPolicy::class)->counterMethods())->toBe(['cash']);
});
