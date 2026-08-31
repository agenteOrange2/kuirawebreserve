<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\SettleStay;
use App\Actions\Reservations\TransitionReservation;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\ReservationController;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Refund;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\CashCutService;
use App\Services\ReservationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '501',
    ]);
    $this->blockPlan = RatePlan::factory()->block(720, 1200)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function guaranteeSettings(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

function checkOutRequest(array $payload = []): Request
{
    return Request::create('/api/stays/x/check-out', 'PATCH', $payload);
}

it('con el ajuste apagado nada cambia: el walk-in ignora guarantee_method (regresión)', function () {
    expect(app(ReservationPolicy::class)->guaranteeEnabled())->toBeFalse();

    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Sin Fianza',
        'guarantee_method' => 'cash',
    ]);

    expect($stay->payments()->count())->toBe(0);
});

it('interruptor prendido pero monto 0 = fianza apagada', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 0]);

    expect(app(ReservationPolicy::class)->guaranteeEnabled())->toBeFalse();
});

it('walk-in con fianza activa crea el Payment kind guarantee sin tocar el folio', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 500]);

    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Con Fianza',
        'guarantee_method' => 'card',
    ]);

    $guarantee = $stay->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    expect($guarantee)->not->toBeNull()
        ->and((float) $guarantee->amount)->toEqual(500.0)
        ->and($guarantee->method)->toBe('card')
        // La fianza NO infla el folio de hospedaje: sigue todo pendiente.
        ->and($stay->folio()['lodging_paid'])->toEqual(0.0)
        ->and($stay->folio()['lodging_pending'])->toEqual((float) $stay->amount);
});

it('el check-in de una reserva cobra la fianza ligada solo a la estancia', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 350]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'guest_name' => 'Llega Con Fianza',
    ]);

    $request = Request::create('/api/reservations/x/check-in', 'PATCH', ['guarantee_method' => 'cash']);
    $response = app(ReservationController::class)->checkIn($request, $reservation, app(TransitionReservation::class));

    expect($response->getStatusCode())->toBe(200);

    $stay = $reservation->refresh()->stay;
    $guarantee = $stay->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    expect($guarantee)->not->toBeNull()
        ->and((float) $guarantee->amount)->toEqual(350.0)
        ->and($guarantee->reservation_id)->toBeNull()
        // No contamina el control de pagos de la reserva ni su folio.
        ->and($reservation->refresh()->paidTotal())->toEqual(0.0)
        ->and($stay->folio()['lodging_paid'])->toEqual(0.0);
});

it('el check-out devuelve la fianza por default (Refund manual)', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 500]);

    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Salida Limpia',
        'payment_method' => 'cash', // hospedaje pagado: sin saldo al salir
        'guarantee_method' => 'cash',
    ]);

    $response = app(StayController::class)->checkOut(
        checkOutRequest(),
        $stay,
        app(TransitionReservation::class),
        app(SettleStay::class),
    );

    expect($response->getStatusCode())->toBe(200);

    $guarantee = $stay->payments()->where('kind', Payment::KIND_GUARANTEE)->first();
    $refund = Refund::query()->where('payment_id', $guarantee->id)->first();

    expect($refund)->not->toBeNull()
        ->and((float) $refund->amount)->toEqual(500.0)
        ->and($refund->gateway)->toBeNull() // devolución manual en mostrador
        ->and($guarantee->refresh()->refundableAmount())->toEqual(0.0);
});

it('retener la fianza exige motivo y no crea refund', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 500]);

    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Retiene',
        'payment_method' => 'cash',
        'guarantee_method' => 'cash',
    ]);

    // Sin motivo: se rechaza — quedarse la fianza nunca es un olvido.
    $failed = app(StayController::class)->checkOut(
        checkOutRequest(['guarantee_refund' => false]),
        $stay,
        app(TransitionReservation::class),
        app(SettleStay::class),
    );

    expect($failed->getStatusCode())->toBe(422)
        ->and($stay->refresh()->status)->toBe(\App\Models\Stay::STATUS_ACTIVE);

    // Con motivo: se retiene, sin refund, y el porqué queda en el pago.
    $response = app(StayController::class)->checkOut(
        checkOutRequest(['guarantee_refund' => false, 'guarantee_retain_reason' => 'Toalla quemada']),
        $stay,
        app(TransitionReservation::class),
        app(SettleStay::class),
    );

    $guarantee = $stay->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    expect($response->getStatusCode())->toBe(200)
        ->and(Refund::query()->count())->toBe(0)
        ->and($guarantee->notes)->toContain('Fianza retenida: Toalla quemada');
});

it('la fianza no es venta: fuera de los totales del corte, dentro del efectivo esperado', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 500]);

    $cashier = User::factory()->create();

    app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Cliente Corte',
        'payment_method' => 'cash',
        'guarantee_method' => 'cash',
    ], $cashier);

    $cut = app(CashCutService::class)->compute($cashier, now()->subHour(), now()->addHour());

    expect($cut['payments_total'])->toEqual(1200.0) // solo hospedaje
        ->and($cut['cash_total'])->toEqual(1200.0)
        ->and($cut['grand_total'])->toEqual(1200.0)
        ->and($cut['guarantees_collected'])->toEqual(500.0)
        // El cajón sí contiene la fianza en efectivo: arqueo la espera.
        ->and($cut['expected_cash'])->toEqual(1700.0);
});

/* ── Escalones por volumen y ajuste en el mostrador ──────────────────── */

it('sin escalones el monto base aplica a cualquier cantidad de habitaciones', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 1500]);

    $policy = app(ReservationPolicy::class);

    expect($policy->guaranteeAmountFor(1))->toEqual(1500.0)
        ->and($policy->guaranteeAmountFor(5))->toEqual(1500.0);
});

it('el escalón baja la fianza POR HABITACIÓN a partir de la cantidad configurada', function () {
    guaranteeSettings([
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
        'guarantee_tiers' => [['from' => 3, 'amount' => 1000]],
    ]);

    $policy = app(ReservationPolicy::class);

    // Dos cabañas siguen pagando el monto base; a partir de la tercera baja
    // el precio unitario, así que el grupo de 3 deja lo mismo que el de 2.
    expect($policy->guaranteeAmountFor(1))->toEqual(1500.0)
        ->and($policy->guaranteeAmountFor(2))->toEqual(1500.0)
        ->and($policy->guaranteeAmountFor(3))->toEqual(1000.0)
        ->and($policy->guaranteeAmountFor(3) * 3)->toEqual(3000.0)
        ->and($policy->guaranteeAmountFor(9))->toEqual(1000.0);
});

it('gana el escalón más alto que la cantidad alcanza, sin importar el orden guardado', function () {
    guaranteeSettings([
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
        // A propósito desordenados y con basura que debe descartarse:
        // `from` 1 sería el monto base con otro nombre.
        'guarantee_tiers' => [
            ['from' => 6, 'amount' => 700],
            ['from' => 1, 'amount' => 99],
            ['from' => 3, 'amount' => 1000],
        ],
    ]);

    $policy = app(ReservationPolicy::class);

    expect($policy->guaranteeTiers())->toHaveCount(2)
        ->and($policy->guaranteeAmountFor(2))->toEqual(1500.0)
        ->and($policy->guaranteeAmountFor(4))->toEqual(1000.0)
        ->and($policy->guaranteeAmountFor(6))->toEqual(700.0);
});

it('cada reserva del grupo cobra el escalón que le toca, no el monto base', function () {
    guaranteeSettings([
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
        'guarantee_tiers' => [['from' => 3, 'amount' => 1000]],
    ]);

    $group = \App\Models\ReservationGroup::create([
        'property_id' => test()->property->id,
        'code' => 'GRP-TEST',
        'guest_name' => 'Grupo Cabañas',
    ]);

    $reservations = collect(range(1, 3))->map(function (int $i) use ($group) {
        $room = Room::factory()->create([
            'property_id' => test()->property->id,
            'room_type_id' => test()->roomType->id,
            'number' => '60'.$i,
        ]);

        $reservation = app(CreateReservation::class)->handle([
            'rate_plan_id' => test()->blockPlan->id,
            'room_id' => $room->id,
            'starts_at' => now()->addHour(),
            'confirmed' => true,
            'guest_name' => 'Grupo Cabañas',
        ]);

        $reservation->update(['reservation_group_id' => $group->id]);

        return $reservation->refresh();
    });

    expect($reservations->first()->partyRoomCount())->toBe(3);

    $total = $reservations->sum(function ($reservation) {
        $request = Request::create('/api/reservations/x/check-in', 'PATCH', ['guarantee_method' => 'cash']);
        app(ReservationController::class)->checkIn($request, $reservation, app(TransitionReservation::class));

        return (float) $reservation->refresh()->stay
            ->payments()->where('kind', Payment::KIND_GUARANTEE)->first()->amount;
    });

    // $3,000 en total, no $4,500: es justo el punto del escalón.
    expect($total)->toEqual(3000.0);
});

it('una reserva suelta no se contagia del escalón', function () {
    guaranteeSettings([
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
        'guarantee_tiers' => [['from' => 3, 'amount' => 1000]],
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'guest_name' => 'Va Solo',
    ]);

    expect($reservation->partyRoomCount())->toBe(1)
        ->and(app(ReservationPolicy::class)->guaranteeAmountForReservation($reservation))
        ->toEqual(1500.0);
});

it('el mostrador puede cobrar otro monto, pero solo con motivo', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 1500]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'guest_name' => 'Ajuste Sin Motivo',
    ]);

    $request = Request::create('/api/reservations/x/check-in', 'PATCH', [
        'guarantee_method' => 'cash',
        'guarantee_amount' => 900,
    ]);
    $response = app(ReservationController::class)->checkIn($request, $reservation, app(TransitionReservation::class));

    // 422 y NADA cobrado: un depósito distinto sin explicación es lo que
    // hace impagable la devolución tres días después.
    expect($response->getStatusCode())->toBe(422)
        ->and(Payment::query()->where('kind', Payment::KIND_GUARANTEE)->count())->toBe(0);
});

it('el ajuste con motivo se cobra y deja rastro de cuánto pedía la política', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 1500]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'guest_name' => 'Ajuste Con Motivo',
    ]);

    $request = Request::create('/api/reservations/x/check-in', 'PATCH', [
        'guarantee_method' => 'cash',
        'guarantee_amount' => 900,
        'guarantee_reason' => 'Solo traía $900 en efectivo; autorizó el gerente',
    ]);
    app(ReservationController::class)->checkIn($request, $reservation, app(TransitionReservation::class));

    $guarantee = $reservation->refresh()->stay
        ->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    expect((float) $guarantee->amount)->toEqual(900.0)
        ->and($guarantee->notes)->toContain('la política pedía $1,500.00')
        ->and($guarantee->notes)->toContain('autorizó el gerente');
});

it('mandar el mismo monto de la política no cuenta como ajuste y no pide motivo', function () {
    guaranteeSettings(['guarantee_enabled' => true, 'guarantee_amount' => 1500]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->blockPlan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'guest_name' => 'Mismo Monto',
    ]);

    $request = Request::create('/api/reservations/x/check-in', 'PATCH', [
        'guarantee_method' => 'cash',
        'guarantee_amount' => 1500.00,
    ]);
    $response = app(ReservationController::class)->checkIn($request, $reservation, app(TransitionReservation::class));

    $guarantee = $reservation->refresh()->stay
        ->payments()->where('kind', Payment::KIND_GUARANTEE)->first();

    expect($response->getStatusCode())->toBe(200)
        ->and((float) $guarantee->amount)->toEqual(1500.0)
        ->and($guarantee->notes)->not->toContain('ajustado');
});

it('el huésped se entera de la fianza antes de venir: viaja al wizard y al bot', function () {
    guaranteeSettings([
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
        'guarantee_tiers' => [['from' => 3, 'amount' => 1000]],
    ]);

    $public = app(ReservationPolicy::class)->guaranteePublic();

    expect($public['amount'])->toEqual(1500.0)
        ->and($public['label'])->toContain('$1,500.00')
        ->and($public['label'])->toContain('se te devuelve')
        ->and($public['tiers_label'])->toContain('desde 3 habitaciones');

    // Con la fianza apagada no se le anuncia nada a nadie.
    guaranteeSettings(['guarantee_enabled' => false]);
    expect(app(ReservationPolicy::class)->guaranteePublic())->toBeNull();
});
