<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Payments\RegisterGatewayPayment;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    // Tarifa con anticipo del 20% y saldo una semana antes de llegar.
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
        'deposit_percent' => 20,
        'payment_due_unit' => 'week',
        'payment_due_value' => 1,
    ]);
});

function apartar(array $overrides = []): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(30)->setTime(15, 0),
        'ends_at' => now()->addDays(32)->setTime(12, 0), // 2 noches → $1000
        'confirmed' => false, // hold, como los crea el bot
        ...$overrides,
    ]);
}

it('emite la solicitud del anticipo y extiende el hold mientras viva', function () {
    $reservation = apartar();
    $holdBefore = $reservation->hold_expires_at;

    $request = app(IssuePaymentRequest::class)->handle($reservation);

    expect($request->concept)->toBe(PaymentRequest::CONCEPT_DEPOSIT)
        ->and((float) $request->amount)->toBe(200.0) // 20% de $1000
        ->and($request->method)->toBe(PaymentRequest::METHOD_TRANSFER)
        ->and($request->status)->toBe(PaymentRequest::STATUS_PENDING)
        ->and($request->expires_at)->not->toBeNull()
        ->and($reservation->refresh()->hold_expires_at->gt($holdBefore))->toBeTrue()
        ->and($reservation->hold_expires_at->toDateTimeString())
        ->toBe($request->expires_at->toDateTimeString());
});

it('es idempotente: pedir el mismo cobro devuelve la solicitud viva', function () {
    $reservation = apartar();
    $action = app(IssuePaymentRequest::class);

    $first = $action->handle($reservation);
    $second = $action->handle($reservation->refresh());

    expect($second->id)->toBe($first->id)
        ->and(PaymentRequest::count())->toBe(1);
});

it('cobra pago total cuando la tarifa no define anticipo parcial', function () {
    $libre = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);
    $reservation = apartar(['rate_plan_id' => $libre->id]);

    $request = app(IssuePaymentRequest::class)->handle($reservation);

    expect($request->concept)->toBe(PaymentRequest::CONCEPT_FULL)
        ->and((float) $request->amount)->toBe(1000.0);
});

it('aprobar la transferencia registra el pago y confirma la reserva sola', function () {
    $reservation = apartar();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    $payment = app(RegisterGatewayPayment::class)->handle($request, ['reference' => 'SPEI-777']);

    $reservation->refresh();
    $request->refresh();

    expect($payment->method)->toBe('transfer')
        ->and($payment->payment_request_id)->toBe($request->id)
        ->and($request->status)->toBe(PaymentRequest::STATUS_PAID)
        ->and($request->payment_id)->toBe($payment->id)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->hold_expires_at)->toBeNull();
});

it('respeta el ajuste de no auto-confirmar', function () {
    $this->property->update(['settings' => ['auto_confirm_on_payment' => false]]);

    $reservation = apartar();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    app(RegisterGatewayPayment::class)->handle($request);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid);
});

it('tras el anticipo, la siguiente solicitud es por el saldo', function () {
    $reservation = apartar();
    $issue = app(IssuePaymentRequest::class);

    app(RegisterGatewayPayment::class)->handle($issue->handle($reservation));

    $balance = $issue->handle($reservation->refresh());

    expect($balance->concept)->toBe(PaymentRequest::CONCEPT_BALANCE)
        ->and((float) $balance->amount)->toBe(800.0);
});

it('un pago que llega con la reserva cancelada la revive si hay disponibilidad', function () {
    $reservation = apartar();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    // El hold venció y el scheduler la canceló antes de que llegara el dinero.
    $reservation->update(['status' => ReservationStatus::Cancelled, 'hold_expires_at' => null]);

    app(RegisterGatewayPayment::class)->handle($request);

    $reservation->refresh();
    $request->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($request->meta['revived'] ?? false)->toBeTrue();
});

it('si la habitación ya se vendió, el pago queda registrado y alerta', function () {
    $reservation = apartar();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    $reservation->update(['status' => ReservationStatus::Cancelled, 'hold_expires_at' => null]);

    // Otro huésped ganó la habitación en las mismas fechas.
    $rival = apartar(['guest_name' => 'Rival']);
    app(TransitionReservation::class)->confirm($rival);

    app(RegisterGatewayPayment::class)->handle($request);

    $reservation->refresh();
    $request->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->paidTotal())->toBe(200.0) // el dinero SIEMPRE se registra
        ->and($request->status)->toBe(PaymentRequest::STATUS_PAID)
        ->and($request->meta['requires_attention'] ?? false)->toBeTrue();
});

it('rechazar y reintentar un evento pagado son seguros', function () {
    $reservation = apartar();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $action = app(RegisterGatewayPayment::class);

    $payment = $action->handle($request);
    $replay = $action->handle($request->refresh()); // webhook/reintento duplicado

    expect($replay->id)->toBe($payment->id)
        ->and($reservation->refresh()->payments()->count())->toBe(1);
});

it('el command vence solicitudes cuya vigencia pasó', function () {
    $reservation = apartar();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    $request->update(['expires_at' => now()->subMinute()]);

    $this->artisan('payments:expire-requests')->assertSuccessful();

    expect($request->refresh()->status)->toBe(PaymentRequest::STATUS_EXPIRED);
});

it('emitir un cobro nuevo cancela la solicitud anterior si el monto cambió', function () {
    $reservation = apartar();
    $issue = app(IssuePaymentRequest::class);

    $first = $issue->handle($reservation);

    // El total cambió (p. ej. se extendió la estancia): el cobro viejo muere.
    $reservation->refresh()->update(['total_amount' => 2000, 'deposit_amount' => 400]);
    $second = $issue->handle($reservation->refresh());

    expect($first->refresh()->status)->toBe(PaymentRequest::STATUS_CANCELED)
        ->and($second->status)->toBe(PaymentRequest::STATUS_PENDING)
        ->and((float) $second->amount)->toBe(400.0);
});

it('aprobar con comprobante lo adjunta como evidencia y el detalle lo expone', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $reservation = apartar(['guest_name' => 'Ana García', 'guest_phone' => '6141234567']);
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    $httpRequest = \Illuminate\Http\Request::create('/api/payment-requests', 'POST', [], [], [
        'receipt' => \Illuminate\Http\UploadedFile::fake()->image('comprobante.jpg'),
    ]);
    $httpRequest->setUserResolver(fn () => null);

    $response = app(\App\Http\Controllers\Tenant\PaymentRequestController::class)
        ->approve($httpRequest, $request, app(RegisterGatewayPayment::class));

    expect($response->getStatusCode())->toBe(200)
        ->and($request->refresh()->status)->toBe(PaymentRequest::STATUS_PAID)
        ->and($request->getFirstMedia('receipt'))->not->toBeNull()
        ->and($request->receiptPayload()['is_image'])->toBeTrue();
});

it('el detalle de la solicitud trae huésped, montos del sujeto y cuentas del hotel', function () {
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
    ]]);

    $reservation = apartar(['guest_name' => 'Ana García', 'guest_phone' => '6141234567']);
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    $data = app(\App\Http\Controllers\Tenant\PaymentRequestController::class)->show($request)->getData(true);

    expect($data['subject_code'])->toBe($reservation->displayCode())
        ->and($data['guest']['name'])->toBe('Ana García')
        ->and($data['guest']['phone'])->toBe('6141234567')
        ->and($data['bank_accounts'])->toHaveCount(1)
        ->and(collect($data['details'])->firstWhere('label', 'Saldo pendiente'))->not->toBeNull()
        ->and($data['receipt'])->toBeNull();
});

it('los últimos pagos de /pagos paginan, filtran por método y marcan reembolsos', function () {
    \Spatie\Permission\Models\Permission::findOrCreate('reservations.manage', 'web');
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('reservations.manage');

    $reservation = apartar(['guest_name' => 'Laura Paginada']);
    foreach (range(1, 17) as $i) {
        $reservation->payments()->create([
            'amount' => 100 + $i,
            'method' => $i % 2 ? 'cash' : 'transfer',
            'kind' => \App\Models\Payment::KIND_LODGING,
            'paid_at' => now(),
        ]);
    }

    // Un reembolso completado cambia el estatus de ese pago.
    $refunded = $reservation->payments()->latest('id')->first();
    \App\Models\Refund::create([
        'payment_id' => $refunded->id,
        'reservation_id' => $reservation->id,
        'amount' => (float) $refunded->amount,
        'status' => \App\Models\Refund::STATUS_COMPLETED,
        'refunded_at' => now(),
    ]);

    $props = function (array $query = []) use ($user): array {
        $request = \Illuminate\Http\Request::create('/pagos', 'GET', $query);
        $request->headers->set('X-Inertia', 'true');
        $request->setUserResolver(fn () => $user);

        return app(\App\Http\Controllers\Tenant\PaymentsPageController::class)($request)
            ->toResponse($request)->getData(true)['props'];
    };

    $page1 = $props()['recentPayments'];
    expect($page1['total'])->toBe(17)
        ->and($page1['data'])->toHaveCount(15)
        ->and($page1['last_page'])->toBe(2)
        ->and($page1['data'][0]['status'])->toBe('refunded')
        ->and($page1['data'][0]['status_label'])->toBe('Reembolsado')
        ->and($page1['data'][1]['status'])->toBe('registered');

    $page2 = $props(['payments_page' => 2])['recentPayments'];
    expect($page2['data'])->toHaveCount(2)
        ->and($page2['current_page'])->toBe(2);

    $cash = $props(['method' => 'cash'])['recentPayments'];
    expect($cash['total'])->toBe(9);

    $porNombre = $props(['q' => 'Laura'])['recentPayments'];
    expect($porNombre['total'])->toBe(17);

    $porFolio = $props(['q' => $reservation->displayCode()])['recentPayments'];
    expect($porFolio['total'])->toBe(17);
});
