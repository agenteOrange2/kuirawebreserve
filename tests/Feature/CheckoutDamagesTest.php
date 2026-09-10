<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\SettleStay;
use App\Actions\Reservations\TransitionReservation;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Daños de la salida y fianza.
 *
 * Tres cosas estaban mal y las tres costaban dinero: el daño de una estancia
 * nacida de reserva no aparecía en la cuenta (folio lee el total de la
 * RESERVA, no el de la estancia), un cargo mal tecleado no se podía quitar, y
 * "retener la fianza" se quedaba el depósito ADEMÁS de cobrar la cuenta
 * completa, aunque la pantalla prometiera "puedes cubrirlo con ella".
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->property->update(['settings' => [
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
    ]]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '505',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1950,
    ]);
});

/** Estancia de una reserva de $1,950 ya pagada, con fianza de $1,500 cobrada. */
function estanciaConFianza(): Stay
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addDay()->setTime(11, 0),
        'confirmed' => true,
        'guest_name' => 'Rafael Marquez',
    ]);

    $reservation->payments()->create([
        'amount' => 1950,
        'method' => 'cash',
        'kind' => Payment::KIND_LODGING,
        'paid_at' => now(),
    ]);

    return app(TransitionReservation::class)->checkIn($reservation, null, [], 'cash');
}

function stayController(): StayController
{
    return app(StayController::class);
}

function cargar(Stay $stay, string $concept, float $amount)
{
    $request = Request::create("/api/stays/{$stay->id}/charges", 'POST', [
        'concept' => $concept,
        'amount' => $amount,
        'kind' => 'damage',
    ]);
    $request->setUserResolver(fn () => null);

    return stayController()->addCharge($request, $stay);
}

it('el daño de una estancia con reserva sí sube la cuenta', function () {
    $stay = estanciaConFianza();

    expect($stay->folio()['grand_pending'])->toBe(0.0);

    $response = cargar($stay, 'Toalla quemada', 250);
    $folio = $response->getData(true);

    // Antes esto quedaba en 0: el cargo subía stay.amount y folio() leía el
    // total de la reserva, así que el daño no se cobraba nunca.
    expect((float) $folio['grand_pending'])->toBe(250.0)
        ->and((float) $folio['damages_total'])->toBe(250.0)
        ->and($folio['damages'])->toHaveCount(1)
        ->and($folio['damages'][0]['concept'])->toBe('Toalla quemada');
});

it('un cargo mal capturado se quita y la cuenta vuelve a su monto', function () {
    $stay = estanciaConFianza();

    $folio = cargar($stay, 'Cortina rota', 900)->getData(true);
    $id = $folio['damages'][0]['id'];

    $folio = stayController()->destroyCharge($stay->refresh(), $id)->getData(true);

    expect((float) $folio['grand_pending'])->toBe(0.0)
        ->and($folio['damages'])->toBeEmpty()
        // Y el total de la reserva regresa a lo que era.
        ->and((float) $stay->refresh()->reservation->total_amount)->toBe(1950.0);
});

it('también se quita un cargo viejo, de los que no traen id', function () {
    $stay = estanciaConFianza();

    // Como los que ya estaban en la base antes de que los cargos tuvieran id:
    // se ubican por posición. Derivar el id de la línea daba cadena vacía, así
    // que la cuenta bajaba y el cargo se quedaba en la lista para siempre.
    $stay->forceFill([
        'extra_charges' => [
            ['kind' => 'damage', 'amount' => 700, 'concept' => 'Mosquitero roto'],
        ],
        'amount' => 2650,
    ])->saveQuietly();

    $folio = stayController()->destroyCharge($stay->refresh(), '0')->getData(true);

    expect($folio['damages'])->toBeEmpty()
        ->and((float) $folio['damages_total'])->toBe(0.0)
        ->and((float) $stay->refresh()->amount)->toBe(1950.0);
});

it('la fianza cubre los daños y solo se cobra el excedente', function () {
    $stay = estanciaConFianza();
    cargar($stay, 'Vidrio roto', 2500);

    $request = Request::create("/api/stays/{$stay->id}/check-out", 'PATCH', [
        'payment_method' => 'cash',
        'guarantee_refund' => false,
        'guarantee_retain_reason' => 'Vidrio roto',
    ]);
    $request->setUserResolver(fn () => null);

    $response = stayController()->checkOut(
        $request,
        $stay->refresh(),
        app(TransitionReservation::class),
        app(SettleStay::class),
    );

    expect($response->getStatusCode())->toBe(200);

    $stay->refresh();

    // La cuenta queda en ceros: $1,500 los puso la fianza y $1,000 el huésped.
    expect($stay->folio()['grand_pending'])->toBe(0.0);

    $cubierto = $stay->payments()
        ->where('kind', Payment::KIND_LODGING)
        ->get()
        ->firstWhere('notes', 'Cubierto con la fianza: Vidrio roto');

    expect($cubierto)->not->toBeNull()
        ->and((float) $cubierto->amount)->toBe(1500.0)
        // El depósito deja de ser pasivo: se registra su salida para que el
        // arqueo no cuente dos veces el mismo billete.
        ->and($stay->payments()->where('kind', Payment::KIND_GUARANTEE)->first()->refundableAmount())
        ->toBe(0.0);
});

it('si la fianza sobra, lo que no cubrió se le devuelve al huésped', function () {
    $stay = estanciaConFianza();
    cargar($stay, 'Toalla quemada', 400);

    $request = Request::create("/api/stays/{$stay->id}/check-out", 'PATCH', [
        'guarantee_refund' => false,
        'guarantee_retain_reason' => 'Toalla quemada',
    ]);
    $request->setUserResolver(fn () => null);

    stayController()->checkOut(
        $request,
        $stay->refresh(),
        app(TransitionReservation::class),
        app(SettleStay::class),
    );

    $stay->refresh();
    $fianza = $stay->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    expect($stay->folio()['grand_pending'])->toBe(0.0)
        // Cubrió 400 de los 1,500: quedan 1,100 que siguen siendo del huésped.
        ->and($fianza->refundableAmount())->toBe(1100.0);
});

it('sin cuenta que cubrir, retener la fianza sigue siendo una penalización', function () {
    $stay = estanciaConFianza();

    $request = Request::create("/api/stays/{$stay->id}/check-out", 'PATCH', [
        'guarantee_refund' => false,
        'guarantee_retain_reason' => 'Se llevó las sábanas',
    ]);
    $request->setUserResolver(fn () => null);

    stayController()->checkOut(
        $request,
        $stay->refresh(),
        app(TransitionReservation::class),
        app(SettleStay::class),
    );

    $fianza = $stay->refresh()->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    // No se devuelve ni se convierte en venta: se queda retenida con su motivo.
    expect($fianza->refundableAmount())->toBe(1500.0)
        ->and($fianza->notes)->toContain('Fianza retenida: Se llevó las sábanas');
});

it('devolver la fianza es lo de siempre: regresa completa', function () {
    $stay = estanciaConFianza();

    $request = Request::create("/api/stays/{$stay->id}/check-out", 'PATCH', []);
    $request->setUserResolver(fn () => null);

    stayController()->checkOut(
        $request,
        $stay->refresh(),
        app(TransitionReservation::class),
        app(SettleStay::class),
    );

    $fianza = $stay->refresh()->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    expect($fianza->refundableAmount())->toBe(0.0)
        ->and($stay->status)->toBe(Stay::STATUS_COMPLETED);
});
