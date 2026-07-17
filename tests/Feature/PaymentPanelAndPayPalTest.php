<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Payments\RegisterGatewayPayment;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\UpdateReservation;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\ReservationController;
use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Payments\PayPalGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create([
        'settings' => ['bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '0123', 'active' => true]]],
    ]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
        'deposit_percent' => 20,
    ]);
});

function reservaF3(): \App\Models\Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(20)->setTime(15, 0),
        'ends_at' => now()->addDays(22)->setTime(12, 0), // 2 noches → $1000
        'confirmed' => false,
    ]);
}

function paypalLink(): PaymentGatewayLink
{
    return PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'paypal',
        'mode' => 'test',
        'public_key' => 'client-id-123',
        'secret_key' => 'secret-123',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);
}

it('cancela el cobro pendiente cuando el total de la reserva cambia (§6.4)', function () {
    $reservation = reservaF3();
    $request = app(IssuePaymentRequest::class)->handle($reservation);
    expect($request->status)->toBe(PaymentRequest::STATUS_PENDING);

    // Tarifa más cara: el total cambia → el cobro viejo ya no aplica.
    $pricier = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 900,
        'deposit_percent' => 20,
    ]);

    app(UpdateReservation::class)->handle($reservation, [
        'rate_plan_id' => $pricier->id,
        'starts_at' => $reservation->starts_at->toDateTimeString(),
        'ends_at' => $reservation->ends_at->toDateTimeString(),
    ]);

    expect($request->refresh()->status)->toBe(PaymentRequest::STATUS_CANCELED);
});

it('editar solo notas NO cancela el cobro (el total no cambia)', function () {
    $reservation = reservaF3();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    app(UpdateReservation::class)->handle($reservation, [
        'rate_plan_id' => $this->plan->id,
        'starts_at' => $reservation->starts_at->toDateTimeString(),
        'ends_at' => $reservation->ends_at->toDateTimeString(),
        'notes' => 'Llega tarde',
    ]);

    expect($request->refresh()->status)->toBe(PaymentRequest::STATUS_PENDING);
});

it('el panel genera un cobro por transferencia sin pasarela (§7.5)', function () {
    $reservation = reservaF3();

    $response = app(ReservationController::class)->issuePayment(
        Request::create('/x', 'POST'),
        $reservation,
        app(IssuePaymentRequest::class),
    );

    $data = json_decode($response->getContent(), true);

    expect($data['payment_request'])->not->toBeNull()
        ->and($data['payment_request']['method'])->toBe(PaymentRequest::METHOD_TRANSFER)
        ->and($data['payment_request']['checkout_url'])->toBeNull();
});

it('el panel cancela un cobro pendiente (§7.5)', function () {
    $reservation = reservaF3();
    $request = app(IssuePaymentRequest::class)->handle($reservation);

    $response = app(ReservationController::class)->cancelPayment($reservation, $request);
    $data = json_decode($response->getContent(), true);

    expect($request->refresh()->status)->toBe(PaymentRequest::STATUS_CANCELED)
        ->and($data['payment_request'])->toBeNull();
});

it('PayPal crea la orden y devuelve el link de aprobación', function () {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1']),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-1',
            'links' => [
                ['rel' => 'self', 'href' => 'https://api/self'],
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
            ],
        ]),
    ]);

    $reservation = reservaF3();
    $request = app(IssuePaymentRequest::class)->handle($reservation, link: paypalLink());

    expect($request->provider)->toBe('paypal')
        ->and($request->checkout_url)->toContain('sandbox.paypal.com')
        ->and($request->gateway_ref)->toBe('ORDER-1');
});

it('captura la orden aprobada de PayPal y registra el pago', function () {
    $reservation = reservaF3();
    $link = paypalLink();

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1']),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-9',
            'links' => [['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/pay?token=ORDER-9']],
        ]),
    ]);
    $request = app(IssuePaymentRequest::class)->handle($reservation, link: $link);

    // El comprador aprobó: capturamos.
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1']),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-9/capture' => Http::response([
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'custom_id' => $request->uuid,
                'payments' => ['captures' => [[
                    'id' => 'CAP-1',
                    'seller_receivable_breakdown' => ['paypal_fee' => ['value' => '12.34']],
                ]]],
            ]],
        ]),
    ]);

    $event = app(PayPalGateway::class)->capture($link, 'ORDER-9');
    expect($event)->not->toBeNull()
        ->and($event['uuid'])->toBe($request->uuid)
        ->and($event['ref'])->toBe('CAP-1')
        ->and($event['fee'])->toBe(12.34);

    $payment = app(RegisterGatewayPayment::class)->handle($request, [
        'gateway' => 'paypal',
        'gateway_ref' => $event['ref'],
        'fee_amount' => $event['fee'],
    ]);

    expect($payment->method)->toBe(\App\Models\Payment::METHOD_ONLINE)
        ->and($payment->gateway)->toBe('paypal')
        ->and((float) $payment->fee_amount)->toBe(12.34)
        ->and($reservation->refresh()->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed);
});

it('PayPal ignora una captura que no está COMPLETED', function () {
    $link = paypalLink();

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1']),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-X/capture' => Http::response(['status' => 'PENDING']),
    ]);

    expect(app(PayPalGateway::class)->capture($link, 'ORDER-X'))->toBeNull();
});
