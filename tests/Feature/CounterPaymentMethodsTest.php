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

/** Fianza activa: sin monto, ChargeGuarantee no cobra nada y no hay qué probar. */
function conFianza(float $amount = 1000): void
{
    $property = Property::firstOrFail();
    $property->update([
        'settings' => array_merge($property->settings ?? [], [
            'guarantee_enabled' => true,
            'guarantee_amount' => $amount,
        ]),
    ]);
    app()->forgetInstance(ReservationPolicy::class);
}

it('la fianza admite lo que el hotel acepta en el mostrador, y nada más', function () {
    acceptOnly(['cash', 'transfer']);
    conFianza();

    // La terminal está apagada en este hotel: la fianza tampoco la admite.
    expect(fn () => registerWalkIn(['guarantee_method' => 'card']))
        ->toThrow(ValidationException::class);

    // La transferencia sí, con el folio del comprobante: estuvo prohibida
    // "porque la fianza se recibe en la mano", y eso dejaba fuera al hotel
    // que sí cobra depósitos así.
    registerWalkIn([
        'guarantee_method' => 'transfer',
        'guarantee_reference' => 'SPEI-99887',
    ]);

    $guarantee = \App\Models\Payment::query()
        ->where('kind', \App\Models\Payment::KIND_GUARANTEE)
        ->latest('id')
        ->firstOrFail();

    expect($guarantee->method)->toBe('transfer')
        ->and($guarantee->reference)->toBe('SPEI-99887');
});

it('una fianza por transferencia sin folio no se cobra: no habría cómo devolverla', function () {
    acceptOnly(['cash', 'transfer']);
    conFianza();

    expect(fn () => registerWalkIn(['guarantee_method' => 'transfer']))
        ->toThrow(InvalidArgumentException::class, 'folio o referencia');
});

it('el panel comparte la lista para que ninguna pantalla ofrezca de más', function () {
    acceptOnly(['cash']);

    // Es el mismo origen que lee useCounterMethods() en el front.
    expect(app(ReservationPolicy::class)->counterMethods())->toBe(['cash']);
});
