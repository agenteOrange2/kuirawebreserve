<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\RoomStatus;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\StaySettlementController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Cuentas por cerrar.
 *
 * La salida manual exige cobrar el saldo o forzarla a propósito; la del reloj
 * se saltaba las dos cosas y cerraba en silencio. Después no había nada que
 * hacer: los cargos se rechazaban por "estancia no activa" y no existía dónde
 * cobrar tarde, así que el dinero desaparecía del panel. En cabañas eso dejó
 * trece estancias cerradas a las 11:15 sin un peso de hospedaje registrado.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '104',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3500,
    ]);
});

/** Estancia viva de una reserva de $3,500 que nadie ha pagado. */
function estanciaSinPagar(): Stay
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addMinutes(30),
        'confirmed' => true,
        'guest_name' => 'Se fue sin pagar',
    ]);

    return app(TransitionReservation::class)->checkIn($reservation);
}

function settlementAction(string $method, Stay $stay, array $body = [])
{
    $request = Request::create('/api/stays/'.$stay->id.'/settlement', 'POST', $body);
    $request->setUserResolver(fn () => null);

    $controller = app(StaySettlementController::class);

    return match ($method) {
        'pay' => $controller->pay($request, $stay, app(\App\Actions\Reservations\SettleStay::class)),
        'charge' => $controller->charge($request, $stay),
        'checkout' => $controller->checkedOutAt($request, $stay),
        'close' => $controller->close($request, $stay),
        'reopen' => $controller->reopen($stay),
    };
}

it('el cierre automático manda a la bandeja la estancia que quedó debiendo', function () {
    $stay = estanciaSinPagar();

    // El reloj: la salida prevista pasó hace rato.
    $stay->forceFill(['planned_end_at' => now()->subHour()])->saveQuietly();
    $this->artisan('stays:auto-checkout')->assertSuccessful();

    $stay->refresh();

    expect($stay->status)->toBe(Stay::STATUS_COMPLETED)
        // Marcada como cerrada por el reloj: cambia a quién se le pregunta.
        ->and($stay->auto_closed_at)->not->toBeNull()
        ->and($this->room->refresh()->status->getMorphClass())->toBe(RoomStatus::Dirty->value);

    $pendiente = Stay::query()->pendingSettlement()->get();

    expect($pendiente)->toHaveCount(1)
        ->and($pendiente->first()->pendingSettlementAmount())->toBe(3500.0);
});

it('cobrar tarde liquida la cuenta y la saca de la bandeja', function () {
    $stay = estanciaSinPagar();
    $stay->forceFill(['planned_end_at' => now()->subHour()])->saveQuietly();
    $this->artisan('stays:auto-checkout')->assertSuccessful();

    $response = settlementAction('pay', $stay->refresh(), ['method' => 'cash']);

    expect($response->getStatusCode())->toBe(200)
        ->and((float) $response->getData(true)['pending'])->toBe(0.0)
        ->and(Stay::query()->pendingSettlement()->get())->toHaveCount(0);

    $pago = Payment::query()->where('kind', Payment::KIND_LODGING)->latest('id')->firstOrFail();

    expect((float) $pago->amount)->toBe(3500.0)
        ->and($pago->method)->toBe('cash');
});

it('lo que faltó capturar se puede agregar y sube el saldo', function () {
    $stay = estanciaSinPagar();
    $stay->forceFill(['planned_end_at' => now()->subHour()])->saveQuietly();
    $this->artisan('stays:auto-checkout')->assertSuccessful();

    settlementAction('charge', $stay->refresh(), [
        'concept' => 'Consumo del frigobar',
        'amount' => 250,
    ]);

    expect(Stay::query()->pendingSettlement()->get()->first()->pendingSettlementAmount())
        ->toBe(3750.0);
});

it('cerrarla sin cobrar exige motivo y no inventa un pago', function () {
    $stay = estanciaSinPagar();
    $stay->forceFill(['planned_end_at' => now()->subHour()])->saveQuietly();
    $this->artisan('stays:auto-checkout')->assertSuccessful();

    settlementAction('close', $stay->refresh(), ['note' => 'Cortesía por la falla del boiler']);

    $stay->refresh();

    expect($stay->settlement_closed_at)->not->toBeNull()
        ->and($stay->settlement_note)->toBe('Cortesía por la falla del boiler')
        ->and(Stay::query()->pendingSettlement()->get())->toHaveCount(0)
        // Sin pago inventado: el dinero nunca entró al corte.
        ->and(Payment::query()->where('kind', Payment::KIND_LODGING)->count())->toBe(0);

    // Y se puede reabrir si resultó que sí se va a cobrar.
    settlementAction('reopen', $stay->refresh());

    expect(Stay::query()->pendingSettlement()->get())->toHaveCount(1);
});

it('sobre una cuenta ya cerrada con motivo no se cobra sin reabrirla', function () {
    $stay = estanciaSinPagar();
    $stay->forceFill(['planned_end_at' => now()->subHour()])->saveQuietly();
    $this->artisan('stays:auto-checkout')->assertSuccessful();

    settlementAction('close', $stay->refresh(), ['note' => 'Incobrable']);

    $response = settlementAction('pay', $stay->refresh(), ['method' => 'cash']);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('reábrela');
});

it('la hora de salida se corrige, pero no antes de la llegada ni en el futuro', function () {
    $stay = estanciaSinPagar();
    $stay->forceFill(['planned_end_at' => now()->subHour()])->saveQuietly();
    $this->artisan('stays:auto-checkout')->assertSuccessful();

    // La llegada se registra al hacer check-in, o sea "ahora": para probar
    // una corrección creíble se retrocede primero.
    $stay->forceFill(['check_in_at' => now()->subHours(5)])->saveQuietly();

    settlementAction('checkout', $stay->refresh(), [
        'check_out_at' => now()->subMinutes(20)->format('Y-m-d H:i:s'),
    ]);

    expect($stay->refresh()->check_out_at->format('H:i'))
        ->toBe(now()->subMinutes(20)->format('H:i'));

    expect(fn () => settlementAction('checkout', $stay->refresh(), [
        'check_out_at' => now()->addDay()->format('Y-m-d H:i:s'),
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('una estancia pagada no aparece en la bandeja', function () {
    $stay = estanciaSinPagar();
    $stay->reservation->payments()->create([
        'amount' => 3500,
        'method' => 'cash',
        'kind' => Payment::KIND_LODGING,
        'paid_at' => now(),
    ]);

    $stay->forceFill(['planned_end_at' => now()->subHour()])->saveQuietly();
    $this->artisan('stays:auto-checkout')->assertSuccessful();

    expect(Stay::query()->pendingSettlement()->get())->toHaveCount(0);
});
