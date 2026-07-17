<?php

use App\Actions\Payments\RefundPayment;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Events\RoomStatusChanged;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Refund;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    // Tarifa con política: sin costo hasta 2 días antes; después se retiene 50%.
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
        'deposit_percent' => 20,
        'cancel_free_unit' => 'day',
        'cancel_free_value' => 2,
        'cancel_penalty_percent' => 50,
    ]);
});

function reservaPagada(array $planOverrides = []): \App\Models\Reservation
{
    $plan = $planOverrides
        ? RatePlan::factory()->create([
            'property_id' => test()->property->id,
            'room_type_id' => test()->roomType->id,
            'price' => 500,
            ...$planOverrides,
        ])
        : test()->plan;

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(12)->setTime(12, 0), // 2 noches → $1000
        'confirmed' => true,
    ]);

    // Anticipo de $200 pagado en efectivo.
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 200, 'method' => 'cash']);

    return $reservation->refresh();
}

it('sugiere reembolso completo dentro de la ventana y con penalidad fuera', function () {
    $reservation = reservaPagada();

    // Faltan 10 días; la ventana cierra 2 días antes → dentro: todo.
    expect($reservation->suggestedRefund())->toBe(200.0);

    // Cancelando 1 día antes de la llegada → fuera: retiene 50%.
    expect($reservation->suggestedRefund(now()->addDays(9)->setTime(16, 0)))->toBe(100.0);
});

it('sin política no hay sugerencia (decisión 100% humana)', function () {
    $reservation = reservaPagada(['deposit_percent' => 20]);

    expect($reservation->suggestedRefund())->toBeNull();
});

it('penalidad null retiene todo fuera de la ventana', function () {
    $reservation = reservaPagada([
        'deposit_percent' => 20,
        'cancel_free_unit' => 'day',
        'cancel_free_value' => 2,
        'cancel_penalty_percent' => null,
    ]);

    expect($reservation->suggestedRefund(now()->addDays(9)))->toBe(0.0);
});

it('registra un reembolso manual y descuenta lo reembolsable', function () {
    $reservation = reservaPagada();
    $payment = $reservation->payments()->first();

    $refund = app(RefundPayment::class)->handle($payment, 150.0, 'Cancelación en ventana');

    expect($refund->status)->toBe(Refund::STATUS_COMPLETED)
        ->and($refund->gateway)->toBeNull() // efectivo = devolución manual
        ->and($payment->refresh()->refundableAmount())->toBe(50.0)
        ->and($reservation->refundedTotal())->toBe(150.0);
});

it('rechaza reembolsos que exceden lo reembolsable', function () {
    $reservation = reservaPagada();
    $payment = $reservation->payments()->first();

    app(RefundPayment::class)->handle($payment, 150.0);
    app(RefundPayment::class)->handle($payment->refresh(), 100.0); // solo quedan $50
})->throws(InvalidArgumentException::class, 'excede');

it('reembolsa pagos de pasarela via la API del proveedor', function () {
    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_123', 'status' => 'succeeded']),
    ]);

    PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'stripe',
        'mode' => 'test',
        'secret_key' => 'sk_test_1',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);

    $reservation = reservaPagada();
    $payment = $reservation->payments()->create([
        'amount' => 300,
        'method' => Payment::METHOD_ONLINE,
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_777',
        'paid_at' => now(),
        'created_at' => now(),
    ]);

    $refund = app(RefundPayment::class)->handle($payment, 300.0, 'Cancelación');

    expect($refund->gateway)->toBe('stripe')
        ->and($refund->gateway_ref)->toBe('re_123');

    Http::assertSent(fn ($http) => str_contains($http->url(), '/v1/refunds')
        && $http['payment_intent'] === 'pi_777'
        && $http['amount'] == 30000);
});

it('si el proveedor rechaza, no se registra nada', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Charge disputed']], 402)]);

    PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'stripe',
        'mode' => 'test',
        'secret_key' => 'sk_test_1',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);

    $reservation = reservaPagada();
    $payment = $reservation->payments()->create([
        'amount' => 300,
        'method' => Payment::METHOD_ONLINE,
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_777',
        'paid_at' => now(),
        'created_at' => now(),
    ]);

    expect(fn () => app(RefundPayment::class)->handle($payment, 300.0))
        ->toThrow(InvalidArgumentException::class, 'rechazó');

    expect(Refund::count())->toBe(0);
});

it('manual=true solo registra aunque el pago sea de pasarela', function () {
    $reservation = reservaPagada();
    $payment = $reservation->payments()->create([
        'amount' => 300,
        'method' => Payment::METHOD_ONLINE,
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_777',
        'paid_at' => now(),
        'created_at' => now(),
    ]);

    // Sin link conectado y sin Http::fake: manual no toca la API.
    $refund = app(RefundPayment::class)->handle($payment, 300.0, 'Hecho en dashboard', manual: true);

    expect($refund->gateway)->toBeNull()
        ->and($refund->gateway_ref)->toBeNull()
        ->and($payment->refresh()->refundableAmount())->toBe(0.0);
});
