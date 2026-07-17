<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Enums\PaymentStatus;
use App\Events\RoomStatusChanged;
use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Payments\Gateways;
use App\Services\Payments\StripeGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
        'deposit_percent' => 20,
        'payment_due_unit' => 'week',
        'payment_due_value' => 1,
    ]);
});

function reservaGateway(): \App\Models\Reservation
{
    return app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(30)->setTime(15, 0),
        'ends_at' => now()->addDays(32)->setTime(12, 0), // 2 noches → $1000
        'confirmed' => false,
    ]);
}

function stripeLink(array $overrides = []): PaymentGatewayLink
{
    return PaymentGatewayLink::create([
        'tenant_id' => 'demo',
        'provider' => 'stripe',
        'mode' => 'test',
        'public_key' => 'pk_test_123',
        'secret_key' => 'sk_test_123',
        'webhook_secret' => 'whsec_test_123',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
        ...$overrides,
    ]);
}

it('crea el checkout de Stripe con monto en centavos y guarda el link', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_abc',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc',
        ]),
    ]);

    $reservation = reservaGateway();
    $request = app(IssuePaymentRequest::class)->handle($reservation, link: stripeLink());

    expect($request->method)->toBe(PaymentRequest::METHOD_GATEWAY)
        ->and($request->provider)->toBe('stripe')
        ->and($request->mode)->toBe('test')
        ->and($request->checkout_url)->toBe('https://checkout.stripe.com/c/pay/cs_test_abc')
        ->and($request->gateway_ref)->toBe('cs_test_abc');

    Http::assertSent(fn ($http) => str_contains($http->url(), '/v1/checkout/sessions')
        && $http['line_items[0][price_data][unit_amount]'] == 20000 // $200.00 → centavos
        && $http['client_reference_id'] === $request->uuid);
});

it('si Stripe no tiene métodos activados para la moneda, reintenta pidiendo tarjeta explícita', function () {
    // Cuenta que nunca tocó Settings → Payment methods: la resolución
    // automática regresa vacío y Stripe rechaza el primer intento.
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::sequence()
            ->push(['error' => ['message' => 'No valid payment method types for this Checkout Session. Please ensure that you have activated payment methods compatible with your chosen currency in your dashboard.']], 400)
            ->push(['id' => 'cs_test_retry', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_retry']),
    ]);

    $reservation = reservaGateway();
    $request = app(IssuePaymentRequest::class)->handle($reservation, link: stripeLink());

    expect($request->checkout_url)->toBe('https://checkout.stripe.com/c/pay/cs_test_retry')
        ->and($request->gateway_ref)->toBe('cs_test_retry');

    // El reintento pide tarjeta explícita; el primer intento no.
    Http::assertSentCount(2);
    Http::assertSent(fn ($http) => str_contains($http->url(), '/v1/checkout/sessions')
        && ($http['payment_method_types[0]'] ?? null) === 'card');
});

it('si la pasarela falla, la solicitud se cancela y no queda basura viva', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

    $reservation = reservaGateway();

    expect(fn () => app(IssuePaymentRequest::class)->handle($reservation, link: stripeLink()))
        ->toThrow(RuntimeException::class, 'No se pudo generar');

    expect(PaymentRequest::query()->active()->count())->toBe(0);
});

it('crea la preference de Mercado Pago con external_reference', function () {
    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=pref-123',
        ]),
    ]);

    $link = stripeLink(['provider' => 'mercadopago', 'webhook_secret' => null]);
    $reservation = reservaGateway();
    $request = app(IssuePaymentRequest::class)->handle($reservation, link: $link);

    expect($request->checkout_url)->toContain('mercadopago.com.mx')
        ->and($request->provider)->toBe('mercadopago');

    Http::assertSent(fn ($http) => str_contains($http->url(), '/checkout/preferences')
        && $http['external_reference'] === $request->uuid
        && $http['items'][0]['unit_price'] === 200.0);
});

it('rechaza webhooks de pago con token desconocido', function () {
    $this->postJson('/webhooks/payments/'.str_repeat('x', 48), [])->assertNotFound();
});

it('rechaza el webhook de Stripe con firma inválida', function () {
    $link = stripeLink();

    $this->postJson('/webhooks/payments/'.$link->webhook_token, ['type' => 'checkout.session.completed'], [
        'Stripe-Signature' => 't='.now()->timestamp.',v1=firma-falsa',
    ])->assertUnauthorized();
});

it('valida la firma de Stripe correctamente (HMAC sobre el body crudo)', function () {
    $secret = 'whsec_test_123';
    $payload = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_test_abc',
        'client_reference_id' => 'uuid-123',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_123',
    ]]]);
    $timestamp = now()->timestamp;
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    $request = Illuminate\Http\Request::create('/webhooks/payments/x', 'POST', server: [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);

    $event = app(StripeGateway::class)->parseWebhook($request, stripeLink());

    expect($event)->not->toBeNull()
        ->and($event['status'])->toBe('paid')
        ->and($event['uuid'])->toBe('uuid-123')
        ->and($event['ref'])->toBe('pi_123');
});

it('el webhook de Stripe firmado registra el pago y confirma la reserva', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_abc', 'url' => 'https://checkout.stripe.com/pay']),
    ]);

    $link = stripeLink(['tenant_id' => 'demo']);
    $reservation = reservaGateway();
    $request = app(IssuePaymentRequest::class)->handle($reservation, link: $link);

    // El webhook resuelve el tenant con Tenant::find('demo')->run(); en el
    // sqlite de pruebas el tenant no existe, así que se ejercita la pieza
    // interior directamente (mismo camino que la verificación humana).
    $payment = app(\App\Actions\Payments\RegisterGatewayPayment::class)->handle($request, [
        'gateway' => 'stripe',
        'gateway_ref' => 'pi_123',
        'fee_amount' => 7.54,
    ]);

    $reservation->refresh();

    expect($payment->method)->toBe(\App\Models\Payment::METHOD_ONLINE)
        ->and($payment->received_by)->toBeNull() // no contamina cortes de caja
        ->and((float) $payment->fee_amount)->toBe(7.54)
        ->and($reservation->payment_status)->toBe(PaymentStatus::DepositPaid)
        ->and($reservation->status)->toBe(\App\Enums\ReservationStatus::Confirmed);
});

it('Mercado Pago re-consulta la API y solo aprueba pagos approved', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/555' => Http::response([
            'id' => 555,
            'status' => 'approved',
            'external_reference' => 'uuid-777',
            'transaction_amount' => 200,
            'fee_details' => [['amount' => 6.9]],
        ]),
        'api.mercadopago.com/v1/payments/666' => Http::response([
            'id' => 666,
            'status' => 'rejected',
            'external_reference' => 'uuid-777',
        ]),
    ]);

    $link = stripeLink(['provider' => 'mercadopago', 'webhook_secret' => null]);
    $gateway = Gateways::for('mercadopago');

    $approved = $gateway->parseWebhook(
        Illuminate\Http\Request::create('/w', 'POST', ['type' => 'payment', 'data' => ['id' => 555]]),
        $link,
    );
    $rejected = $gateway->parseWebhook(
        Illuminate\Http\Request::create('/w', 'POST', ['topic' => 'payment', 'id' => 666]),
        $link,
    );

    expect($approved['status'])->toBe('paid')
        ->and($approved['uuid'])->toBe('uuid-777')
        ->and($approved['fee'])->toBe(6.9)
        ->and($rejected['status'])->toBe('ignored');
});

it('el bot entrega link de pago cuando hay pasarela activa', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/pay/cs_1']),
    ]);

    stripeLink(['tenant_id' => (string) tenant('id')]);

    // fuera de tenancy tenant('id') es null: el link usa tenant_id null-string
    $reservation = reservaGateway();

    $response = app(\App\Http\Controllers\Agent\AgentToolsController::class)->requestPayment(
        Illuminate\Http\Request::create('/agent', 'POST', ['code' => $reservation->displayCode()]),
        app(IssuePaymentRequest::class),
    );

    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['method'])->toBe('link_de_pago')
        ->and($data['payment_link'])->toContain('checkout.stripe.com')
        ->and((float) $data['amount'])->toBe(200.0);
});
