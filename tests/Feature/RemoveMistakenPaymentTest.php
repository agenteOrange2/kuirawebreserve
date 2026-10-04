<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Payments\RefundPayment;
use App\Actions\Payments\RegisterGatewayPayment;
use App\Actions\Payments\RemoveMistakenPayment;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Enums\PaymentStatus;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\ReservationController;
use App\Models\CashCut;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Payments\PaymentGuestNotifier;
use App\Services\StaffAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

// Caso real cabañas 2026-10-03 (reserva 1789): el anticipo se capturó a mano
// y después se aprobó su comprobante en /pagos. Quedó doble, la reserva
// "Pagada" y ya no dejaba emitir el link del saldo. El huésped pagó una vez.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    // 2 noches de $1,750 = $3,500, anticipo del 50 % = $1,750.
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1750,
        'deposit_percent' => 50,
    ]);
    $this->sofia = User::factory()->create();
});

/** La reserva con el anticipo contado dos veces, como quedó la 1789. */
function anticipoDoble(): array
{
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(20)->setTime(14, 0),
        'ends_at' => now()->addDays(22)->setTime(11, 0),
        'guest_name' => 'Huésped de la 1789',
        'confirmed' => false,
    ]);

    $manual = app(RegisterReservationPayment::class)->handle($reservation->refresh(), [
        'amount' => 1750,
        'method' => 'cash',
    ], test()->sofia);

    // El comprobante se aprobó confirmando "entró dinero de más".
    $request = PaymentRequest::create([
        'reservation_id' => $reservation->id,
        'method' => PaymentRequest::METHOD_TRANSFER,
        'concept' => PaymentRequest::CONCEPT_BALANCE,
        'amount' => 1750,
        'currency' => 'MXN',
        'status' => PaymentRequest::STATUS_PENDING,
        'expires_at' => now()->addDay(),
    ]);
    $approved = app(RegisterGatewayPayment::class)->handle($request, ['confirm_overpay' => true], test()->sofia);

    return [$reservation->refresh(), $manual, $approved, $request->refresh()];
}

/** Nadie se entera: ni el huésped ni el correo al hotel. */
function sinAvisos(): void
{
    test()->mock(PaymentGuestNotifier::class)->shouldNotReceive()->withAnyArgs();
    test()->mock(StaffAlerts::class)->shouldNotReceive()->withAnyArgs();
}

it('quita el pago repetido: la reserva vuelve a pago parcial y deja cobrar el saldo', function () {
    [$reservation, $manual, $approved, $request] = anticipoDoble();

    expect($reservation->payment_status)->toBe(PaymentStatus::Paid)
        ->and($reservation->pendingBalance())->toBe(0.0);

    sinAvisos();

    app(RemoveMistakenPayment::class)->handle($reservation, $approved, 'Anticipo capturado a mano y aprobado otra vez', test()->sofia);

    $reservation->refresh();

    expect(Payment::find($approved->id))->toBeNull()
        ->and(Payment::find($manual->id))->not->toBeNull()
        ->and($reservation->paidTotal())->toBe(1750.0)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->pendingBalance())->toBe(1750.0)
        // El comprobante no vuelve a la cola ni se puede aprobar otra vez.
        ->and($request->refresh()->status)->toBe(PaymentRequest::STATUS_REJECTED)
        ->and($request->payment_id)->toBeNull()
        ->and($request->meta['payment_removed']['payment_id'])->toBe($approved->id);

    // Y ya se puede emitir el cobro del saldo.
    $balance = app(IssuePaymentRequest::class)->handle($reservation);
    expect((float) $balance->amount)->toBe(1750.0);
});

it('deja la foto del pago quitado en la bitácora de la reserva', function () {
    [$reservation, $manual] = anticipoDoble();

    app(RemoveMistakenPayment::class)->handle($reservation, $manual, 'Se capturó dos veces', test()->sofia);

    $entry = Activity::query()
        ->where('subject_type', $reservation::class)
        ->where('subject_id', $reservation->id)
        ->where('log_name', 'payment')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe(test()->sofia->id)
        ->and($entry->description)->toContain('$1,750.00')->toContain('Efectivo')->toContain('Se capturó dos veces')
        ->and($entry->properties['removed_payment']['id'])->toBe($manual->id)
        ->and((float) $entry->properties['removed_payment']['amount'])->toBe(1750.0);
});

it('exige el motivo', function () {
    [$reservation, , $approved] = anticipoDoble();

    expect(fn () => app(RemoveMistakenPayment::class)->handle($reservation, $approved, '   ', test()->sofia))
        ->toThrow(InvalidArgumentException::class);

    expect(Payment::find($approved->id))->not->toBeNull();
});

it('no quita un pago de pasarela: ese dinero sí entró', function () {
    [$reservation, $manual] = anticipoDoble();
    $manual->update(['method' => Payment::METHOD_ONLINE, 'gateway' => 'stripe', 'gateway_ref' => 'pi_123']);

    expect(fn () => app(RemoveMistakenPayment::class)->handle($reservation, $manual->refresh(), 'error', test()->sofia))
        ->toThrow(InvalidArgumentException::class, 'Reembolsar');
});

it('quita también el reembolso registrado a mano para "deshacer" el error (reserva 1789)', function () {
    [$reservation, $manual, $approved] = anticipoDoble();
    // Lo que hizo Sofía el 27-sep: "le confirmé doble vez el primer pago".
    app(RefundPayment::class)->handle($approved, 1750, 'le confirme doble vez el primer pago', test()->sofia, manual: true);

    // El reembolso no baja lo pagado: seguía "Pagada" y sin poder cobrar.
    expect($reservation->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(RemoveMistakenPayment::blockedReason($approved->refresh()))->toBeNull();

    sinAvisos();

    app(RemoveMistakenPayment::class)->handle($reservation, $approved, 'Mismo pago aprobado dos veces', test()->sofia);

    $reservation->refresh();

    expect(\App\Models\Refund::query()->count())->toBe(0)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->pendingBalance())->toBe(1750.0)
        ->and(Activity::query()->where('subject_id', $reservation->id)->where('log_name', 'payment')->latest('id')->value('description'))
        ->toContain('junto con su reembolso');
});

it('no quita un pago con reembolso enviado por la pasarela: ese dinero sí salió', function () {
    [$reservation, $manual] = anticipoDoble();
    \App\Models\Refund::create([
        'payment_id' => $manual->id,
        'reservation_id' => $reservation->id,
        'amount' => 100,
        'status' => \App\Models\Refund::STATUS_COMPLETED,
        'gateway' => 'stripe',
        'refunded_at' => now(),
    ]);

    expect(fn () => app(RemoveMistakenPayment::class)->handle($reservation, $manual, 'error', test()->sofia))
        ->toThrow(InvalidArgumentException::class, 'pasarela');
});

it('no quita cobros del folio de la estancia', function () {
    [$reservation, $manual] = anticipoDoble();
    $manual->update(['kind' => Payment::KIND_GUARANTEE]);

    expect(RemoveMistakenPayment::blockedReason($manual->refresh()))->not->toBeNull();
});

it('el endpoint pide motivo, avisa del corte cerrado y no avisa a nadie', function () {
    [$reservation, , $approved] = anticipoDoble();

    CashCut::create([
        'property_id' => test()->property->id,
        'user_id' => test()->sofia->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'opened_at' => now()->subHour(),
        'closed_at' => now()->addMinute(),
    ]);

    sinAvisos();

    $controller = app(ReservationController::class);
    $request = Request::create('/api/reservations/x/payments/y', 'DELETE', ['reason' => 'Mismo anticipo dos veces']);
    $request->setUserResolver(fn () => test()->sofia);

    $response = $controller->removePayment($request, $reservation, $approved, app(RemoveMistakenPayment::class));
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['payment_status'])->toBe(PaymentStatus::DepositPaid->value)
        ->and($data['closed_cut_notice'])->toContain('ya contaba este pago')
        ->and(collect($data['payments'])->pluck('id')->all())->not->toContain($approved->id)
        ->and($data['payments'][0]['removable'])->toBeTrue();
});
