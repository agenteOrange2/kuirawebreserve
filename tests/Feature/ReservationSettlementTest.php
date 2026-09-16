<?php

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\StaySettlementController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffNotification;
use App\Models\Stay;
use Illuminate\Http\Request;

/**
 * Cuentas por cerrar SIN estancia.
 *
 * La bandeja nació mirando estancias, y eso solo alcanza donde se registran
 * check-ins. El hotel que vende por chat y cobra por transferencia casi
 * nunca abre el plano: el cierre de día completa la reserva y el saldo no
 * aparecía en ninguna pantalla. En cabañas eso son 319 reservas completadas
 * con dinero sin registrar y una brecha entre lo vendido y lo capturado que
 * creció de 48% en julio a 73% en septiembre.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => 'Cabaña Real',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 4500,
        'deposit_percent' => 50,
    ]);
});

/** Reserva confirmada cuya salida ya pasó, con el anticipo pagado. */
function reservaVencidaConAnticipo(float $anticipo = 2250): Reservation
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->subDays(2)->setTime(14, 0),
        'ends_at' => now()->subDay()->setTime(11, 0),
        'confirmed' => true,
        'guest_name' => 'Pagó la mitad por transferencia',
    ]);

    if ($anticipo > 0) {
        Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => $anticipo,
            'method' => 'transfer',
            'kind' => Payment::KIND_LODGING,
            'paid_at' => now()->subDays(3),
        ]);
    }

    $reservation->refresh()->syncPaymentStatus();

    return $reservation->refresh();
}

function reservationSettlement(string $method, Reservation $reservation, array $body = [])
{
    $request = Request::create('/api/reservations/'.$reservation->id.'/settlement', 'PATCH', $body);
    $controller = app(StaySettlementController::class);

    return $method === 'close'
        ? $controller->closeReservation($request, $reservation)
        : $controller->reopenReservation($reservation);
}

it('el cierre de día manda a la bandeja la reserva que quedó debiendo', function () {
    $reservation = reservaVencidaConAnticipo();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    $reservation->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Completed)
        // Nunca hubo estancia: nadie registró la llegada.
        ->and(Stay::query()->count())->toBe(0);

    $pendientes = Reservation::query()->pendingSettlement()->get();

    expect($pendientes)->toHaveCount(1)
        ->and($pendientes->first()->pendingBalance())->toBe(2250.0);
});

it('avisa a la campana en vez de cerrar el saldo en silencio', function () {
    reservaVencidaConAnticipo();

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    $aviso = StaffNotification::query()->where('type', StaffNotification::TYPE_PAYMENT)->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->title)->toBe('Cuenta cerrada con saldo')
        ->and($aviso->body)->toContain('2,250.00')
        ->and($aviso->url)->toBe('/reservas/cuentas');
});

it('la reserva liquidada no llega a la bandeja', function () {
    $reservation = reservaVencidaConAnticipo(4500);

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect(Reservation::query()->pendingSettlement()->get())->toHaveCount(0)
        ->and(StaffNotification::query()->where('type', StaffNotification::TYPE_PAYMENT)->count())->toBe(0);
});

it('la reserva con estancia no se cuenta dos veces', function () {
    $reservation = reservaVencidaConAnticipo();

    Stay::create([
        'reservation_id' => $reservation->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => $reservation->guest_name,
        'num_people' => 2,
        'check_in_at' => now()->subDays(2)->setTime(14, 0),
        'planned_end_at' => now()->subDay()->setTime(11, 0),
        'status' => Stay::STATUS_COMPLETED,
        'check_out_at' => now()->subDay()->setTime(11, 0),
        'amount' => 4500,
        'channel' => 'reservation',
    ]);

    $reservation->update(['status' => ReservationStatus::Completed]);

    // La cubre Stay::pendingSettlement(), donde el saldo además trae los
    // consumos del folio. Contarla en las dos listas la mostraría dos veces
    // con cifras distintas.
    expect(Reservation::query()->pendingSettlement()->get())->toHaveCount(0)
        ->and(Stay::query()->pendingSettlement()->get())->toHaveCount(1);
});

it('cerrar sin cobrar exige motivo y no inventa un pago', function () {
    $reservation = reservaVencidaConAnticipo();
    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();
    $reservation->refresh();

    reservationSettlement('close', $reservation, ['note' => 'Cortesía del dueño por la falla del boiler']);

    $reservation->refresh();

    expect($reservation->settlement_closed_at)->not->toBeNull()
        ->and($reservation->settlement_note)->toBe('Cortesía del dueño por la falla del boiler')
        ->and(Reservation::query()->pendingSettlement()->get())->toHaveCount(0)
        // El dinero NO entró al corte: solo dejó de pedirse.
        ->and($reservation->paidTotal())->toBe(2250.0);

    reservationSettlement('reopen', $reservation);

    expect(Reservation::query()->pendingSettlement()->get())->toHaveCount(1);
});

it('cerrar sin motivo no cierra nada', function () {
    $reservation = reservaVencidaConAnticipo();
    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    reservationSettlement('close', $reservation->refresh(), ['note' => 'ok']);
})->throws(Illuminate\Validation\ValidationException::class);

it('en un hotel que cobra al llegar, la reserva sin ningún pago no dispara la alerta de deuda', function () {
    // Sin anticipo y sin check-in registrado: el cierre de día la asume
    // ocupada, pero lo más probable es que no llegó. Una alerta de "quedó
    // debiendo" por cada no-show enseña al turno a ignorar la campana.
    $reservation = reservaVencidaConAnticipo(0);

    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    expect(StaffNotification::query()->where('type', StaffNotification::TYPE_PAYMENT)->count())->toBe(0);

    // Pero no desaparece: queda en la bandeja marcada para confirmar.
    $req = Request::create('/reservas/cuentas', 'GET');
    $req->headers->set('X-Inertia', 'true');
    $req->setUserResolver(fn () => \App\Models\User::factory()->create());

    $rows = app(StaySettlementController::class)->index($req)->toResponse($req)->getData(true)['props']['reservations']['data'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['code'])->toBe($reservation->displayCode())
        ->and($rows[0]['unpaid'])->toBeTrue();
});

it('el cobro tardío desde la ficha entra al corte de quien cobra hoy', function () {
    $reservation = reservaVencidaConAnticipo();
    $this->artisan('rooms:advance-housekeeping')->assertSuccessful();

    $cajero = \App\Models\User::factory()->create();

    app(\App\Actions\Reservations\RegisterReservationPayment::class)->handle(
        $reservation->refresh(),
        ['amount' => 2250, 'method' => 'card'],
        $cajero,
    );

    $pago = Payment::query()->latest('id')->firstOrFail();

    // received_by + paid_at son las dos llaves con las que CashCutService
    // arma el corte: el turno de 24 h de quien cobró, no el día de la
    // estancia.
    expect($pago->received_by)->toBe($cajero->id)
        ->and($pago->paid_at->isToday())->toBeTrue()
        ->and(Reservation::query()->pendingSettlement()->count())->toBe(0);
});
