<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Caseta de motel en dos momentos: la caseta abre el acceso con la tarifa y el
 * método previsto, y placa, marca, modelo, color y el cobro llegan cuando el
 * encargado regresa con el papel.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        // Fija: la factory sortea 1-4 y estas llegadas son de dos personas.
        'capacity' => 4,
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'available',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 800,
    ]);

    Permission::findOrCreate('reservations.manage', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.manage');
});

function abrirAcceso(array $overrides = []): Stay
{
    return app(CreateWalkInStay::class)->handle(array_replace([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'num_people' => 2,
        // La caseta abre el acceso: sin cobro y sin datos del carro.
        'arrival_pending' => true,
    ], $overrides), test()->user);
}

function completarLlegada(Stay $stay, array $payload): array
{
    $request = Request::create("/api/stays/{$stay->id}/arrival", 'PATCH', $payload);
    $request->setUserResolver(fn () => test()->user);

    return app(StayController::class)->completeArrival($request, $stay)->getData(true);
}

it('la caseta abre el acceso sin cobrar y la llegada queda sin sellar', function () {
    $stay = abrirAcceso();

    expect($stay->arrival_completed_at)->toBeNull()
        ->and($stay->arrivalPending())->toBeTrue()
        ->and($stay->payments()->count())->toBe(0)
        // La habitación se ocupa igual: el acceso ya se dio.
        ->and($stay->room->fresh()->status->getMorphClass())->toBe('occupied')
        // Y queda debiendo, que es la verdad hasta que el encargado cobre.
        ->and((float) $stay->folio()['grand_pending'])->toBe(800.0);
});

it('completar la llegada guarda el carro, crea su ficha y sella el registro', function () {
    $stay = abrirAcceso();

    completarLlegada($stay, [
        'vehicle_plate' => 'xkd-884-p',
        'vehicle_brand' => 'Nissan',
        'vehicle_model' => 'Versa',
        'vehicle_color' => 'Gris',
        'payment_method' => 'cash',
    ]);

    $stay->refresh();
    $vehicle = Vehicle::first();

    expect($stay->arrival_completed_at)->not->toBeNull()
        ->and($stay->arrivalPending())->toBeFalse()
        // En mayúsculas, como se escriben las placas.
        ->and($stay->vehicle_plate)->toBe('XKD-884-P')
        ->and($stay->vehicle_id)->toBe($vehicle->id)
        ->and($vehicle->brand)->toBe('Nissan')
        ->and($vehicle->model)->toBe('Versa')
        // El cobro que hizo el encargado deja el saldo en cero.
        ->and($stay->payments()->where('kind', Payment::KIND_LODGING)->count())->toBe(1)
        ->and((float) $stay->folio()['grand_pending'])->toBe(0.0);
});

it('el cobro entra al corte de quien lo captura, no del que abrió el acceso', function () {
    $stay = abrirAcceso();

    $encargado = User::factory()->create();
    $encargado->givePermissionTo('reservations.manage');

    $request = Request::create("/api/stays/{$stay->id}/arrival", 'PATCH', [
        'payment_method' => 'card',
        'payment_reference' => 'AUTH-77',
    ]);
    $request->setUserResolver(fn () => $encargado);
    app(StayController::class)->completeArrival($request, $stay);

    $payment = Payment::first();

    expect($payment->received_by)->toBe($encargado->id)
        ->and($payment->method)->toBe('card')
        ->and($payment->reference)->toBe('AUTH-77');
});

it('se puede sellar sin datos: el cliente que no quiso darlos existe', function () {
    $stay = abrirAcceso();

    completarLlegada($stay, ['notes' => 'El cliente no dio datos']);

    $stay->refresh();

    expect($stay->arrival_completed_at)->not->toBeNull()
        ->and($stay->vehicle_plate)->toBeNull()
        // Sin cobro marcado, el saldo sigue vivo y se cobra en la salida.
        ->and((float) $stay->folio()['grand_pending'])->toBe(800.0);
});

it('sin la bandera, la llegada nace sellada: el flujo de siempre no cambia', function () {
    $stay = abrirAcceso([
        'arrival_pending' => false,
        'vehicle_plate' => 'ABC-123-D',
        'payment_method' => 'cash',
    ]);

    expect($stay->arrival_completed_at)->not->toBeNull()
        ->and($stay->arrivalPending())->toBeFalse()
        ->and($stay->payments()->count())->toBe(1);
});

it('el daño sube la cuenta y se cobra antes de dejar salir', function () {
    $stay = abrirAcceso(['arrival_pending' => false, 'payment_method' => 'cash']);

    $request = Request::create("/api/stays/{$stay->id}/charges", 'POST', [
        'concept' => 'Toalla quemada',
        'amount' => 180,
    ]);
    $request->setUserResolver(fn () => $this->user);

    $folio = app(StayController::class)->addCharge($request, $stay)->getData(true);
    $stay->refresh();

    expect((float) $stay->amount)->toBe(980.0)
        ->and($stay->extra_charges)->toHaveCount(1)
        ->and($stay->extra_charges[0]['kind'])->toBe('damage')
        // El hospedaje ya estaba pagado: lo que falta es justo el daño.
        ->and((float) $folio['grand_pending'])->toBe(180.0);
});

it('el modo de llegada que eligió la caseta se guarda y viaja al plano', function () {
    $stay = abrirAcceso(['arrival_mode' => 'vehicle']);

    $activa = test()->room->fresh()->toFloorPlanPayload()['active_stay'];

    expect($stay->arrival_mode)->toBe('vehicle')
        // El diálogo de completar lo lee de aquí para no volver a preguntar.
        ->and($activa['arrival_mode'])->toBe('vehicle')
        ->and($activa['arrival_pending'])->toBeTrue();
});

it('a pie también queda anotado', function () {
    expect(abrirAcceso(['arrival_mode' => 'foot'])->arrival_mode)->toBe('foot');
});

it('un modo desconocido no se guarda: el diálogo vuelve a preguntar', function () {
    $stay = abrirAcceso(['arrival_mode' => 'helicóptero']);

    expect($stay->arrival_mode)->toBeNull()
        ->and(test()->room->fresh()->toFloorPlanPayload()['active_stay']['arrival_mode'])
        ->toBeNull();
});
