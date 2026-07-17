<?php

use App\Actions\Experiences\CreateExperienceBooking;
use App\Actions\Experiences\IssueExperiencePayment;
use App\Actions\Payments\RegisterGatewayPayment;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\PaymentRequest;
use App\Models\Property;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $experience = Experience::factory()->create([
        'property_id' => $this->property->id,
        'pricing_mode' => 'per_person',
        'price' => 400,
    ]);
    $session = ExperienceSession::factory()->create(['experience_id' => $experience->id, 'capacity' => 10]);

    $this->booking = app(CreateExperienceBooking::class)->handle([
        'experience_session_id' => $session->id,
        'people' => 3,
        'guest_name' => 'Elliot Alderson',
        'guest_phone' => '5511112233',
    ]);
});

function experienceStripeLink(): PaymentGatewayLink
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
    ]);
}

it('emite el cobro por transferencia por el TOTAL de la experiencia', function () {
    $request = app(IssueExperiencePayment::class)->handle($this->booking);

    expect((float) $request->amount)->toBe(1200.0) // 3 x 400
        ->and($request->concept)->toBe(PaymentRequest::CONCEPT_FULL)
        ->and($request->experience_booking_id)->toBe($this->booking->id)
        ->and($request->reservation_id)->toBeNull()
        ->and($request->method)->toBe(PaymentRequest::METHOD_TRANSFER);
});

it('crea el checkout de Stripe con la etiqueta de experiencia', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_exp',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_exp',
        ]),
    ]);

    $request = app(IssueExperiencePayment::class)->handle($this->booking, link: experienceStripeLink());

    expect($request->checkout_url)->toBe('https://checkout.stripe.com/c/pay/cs_test_exp')
        ->and($request->gateway_ref)->toBe('cs_test_exp');

    Http::assertSent(function ($sent) {
        $name = $sent['line_items[0][price_data][product_data][name]'] ?? '';

        return str_contains($name, 'Experiencia EXP-');
    });
});

it('es idempotente: pedir el mismo cobro devuelve la solicitud viva', function () {
    $first = app(IssueExperiencePayment::class)->handle($this->booking);
    $second = app(IssueExperiencePayment::class)->handle($this->booking);

    expect($second->id)->toBe($first->id)
        ->and(PaymentRequest::query()->active()->count())->toBe(1);
});

it('el pago confirmado registra el dinero y confirma la reserva del tour', function () {
    $request = app(IssueExperiencePayment::class)->handle($this->booking);

    $payment = app(RegisterGatewayPayment::class)->handle($request, ['reference' => 'SPEI-777']);

    expect($payment->reservation_id)->toBeNull()
        ->and($payment->experience_booking_id)->toBe($this->booking->id)
        ->and((float) $payment->amount)->toBe(1200.0)
        ->and($request->fresh()->status)->toBe(PaymentRequest::STATUS_PAID)
        ->and($this->booking->fresh()->status)->toBe(ExperienceBooking::STATUS_CONFIRMED)
        ->and($this->booking->fresh()->isPaid())->toBeTrue();
});

it('una reserva pagada no acepta cobros nuevos', function () {
    $request = app(IssueExperiencePayment::class)->handle($this->booking);
    app(RegisterGatewayPayment::class)->handle($request);

    app(IssueExperiencePayment::class)->handle($this->booking->fresh());
})->throws(InvalidArgumentException::class, 'pagada');
