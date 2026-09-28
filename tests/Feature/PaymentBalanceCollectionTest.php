<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create([
        'settings' => [
            'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
        ],
    ]);
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

/** Reserva confirmada con anticipo pagado + conversación de origen. */
function reservaConSaldo(array $settings = []): array
{
    if ($settings) {
        test()->property->update(['settings' => array_merge(test()->property->settings ?? [], $settings)]);
    }

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays(30)->setTime(15, 0),
        'ends_at' => now()->addDays(32)->setTime(12, 0), // 2 noches → $1000
        'confirmed' => true,
    ]);
    app(RegisterReservationPayment::class)->handle($reservation, ['amount' => 200, 'method' => 'cash']);

    $conversation = Conversation::create([
        'channel_id' => Channel::webchat()->id,
        'reservation_id' => $reservation->id,
        'contact_name' => 'Huésped Prueba',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'last_message_at' => now(),
    ]);

    return [$reservation->refresh(), $conversation];
}

it('emite el cobro del saldo por transferencia y avisa una sola vez', function () {
    [$reservation, $conversation] = reservaConSaldo();
    $reservation->update(['payment_due_at' => now()->addDays(2)]);

    $this->artisan('payments:collect-balance')->assertSuccessful();
    $this->artisan('payments:collect-balance')->assertSuccessful(); // idempotente

    $request = PaymentRequest::query()->where('reservation_id', $reservation->id)->latest('id')->first();
    $messages = $conversation->messages()->where('meta->followup', 'balance_request')->get();

    expect($request)->not->toBeNull()
        ->and($request->concept)->toBe(PaymentRequest::CONCEPT_BALANCE)
        ->and((float) $request->amount)->toBe(800.0)
        ->and($request->expires_at->toDateTimeString())->toBe($reservation->payment_due_at->toDateTimeString())
        ->and($messages)->toHaveCount(1)
        ->and($messages->first()->body)->toContain('$800.00')
        ->and($messages->first()->body)->toContain('BBVA');
});

it('manda recordatorio en las últimas 24 horas', function () {
    [$reservation, $conversation] = reservaConSaldo();
    $reservation->update(['payment_due_at' => now()->addHours(12)]);

    $this->artisan('payments:collect-balance')->assertSuccessful();

    $reminder = $conversation->messages()->where('meta->followup', 'balance_reminder')->first();

    expect($reminder)->not->toBeNull()
        ->and($reminder->body)->toContain('Recordatorio');
});

it('el saldo vencido NO cancela por default (solo alerta)', function () {
    [$reservation, $conversation] = reservaConSaldo();
    $reservation->update(['payment_due_at' => now()->subHours(2)]);

    $this->artisan('payments:collect-balance')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($conversation->messages()->count())->toBe(0);
});

it('cancela el saldo vencido solo si el hotel lo activó', function () {
    [$reservation, $conversation] = reservaConSaldo(['cancel_on_balance_overdue' => true]);
    $reservation->update(['payment_due_at' => now()->subHours(2)]);
    // Se le pidió el saldo antes de vencer: sin ese aviso ya no se cancela
    // (ver PaidReservationSurvivesTest — nadie pierde su cuarto en silencio).
    $conversation->markFollowup('balance_request');

    $this->artisan('payments:collect-balance')->assertSuccessful();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($conversation->messages()->where('meta->followup', 'balance_cancelled')->count())->toBe(1);
});

it('prefiere el link de pasarela para cobrar el saldo', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_bal', 'url' => 'https://checkout.stripe.com/pay/cs_bal']),
    ]);

    PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'stripe',
        'mode' => 'test',
        'secret_key' => 'sk_test_123',
        'webhook_secret' => 'whsec_123',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);

    [$reservation, $conversation] = reservaConSaldo();
    $reservation->update(['payment_due_at' => now()->addDays(2)]);

    $this->artisan('payments:collect-balance')->assertSuccessful();

    $message = $conversation->messages()->where('meta->followup', 'balance_request')->first();
    $request = PaymentRequest::query()->where('reservation_id', $reservation->id)->latest('id')->first();

    expect($request->method)->toBe(PaymentRequest::METHOD_GATEWAY)
        ->and($message->body)->toContain('checkout.stripe.com');
});

it('no cobra a reservas ya pagadas ni fuera de la ventana', function () {
    [$reservation, $conversation] = reservaConSaldo();

    // Fuera de la ventana (faltan 10 días, default 3).
    $reservation->update(['payment_due_at' => now()->addDays(10)]);
    $this->artisan('payments:collect-balance')->assertSuccessful();
    expect($conversation->messages()->count())->toBe(0);

    // Liquidada: dentro de la ventana pero sin saldo.
    app(RegisterReservationPayment::class)->handle($reservation->refresh(), ['amount' => 800, 'method' => 'cash']);
    $reservation->update(['payment_due_at' => now()->addDays(2)]);
    $this->artisan('payments:collect-balance')->assertSuccessful();
    expect($conversation->messages()->count())->toBe(0);
});

it('no usa una pasarela que el hotel apagó aunque siga conectada', function () {
    // Caso real cabañas (2026-09-23/25): Stripe de PRUEBA conectado primero
    // y apagado en Métodos de pago; el barrido lo usaba igual.
    PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'stripe',
        'mode' => 'test',
        'secret_key' => 'sk_test_123',
        'webhook_secret' => 'whsec_123',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);
    app(\App\Services\Payments\PaymentMethodGate::class)->set((string) tenant('id'), 'stripe', false);
    Http::fake();

    [$reservation, $conversation] = reservaConSaldo();
    $reservation->update(['payment_due_at' => now()->addDays(2)]);

    $this->artisan('payments:collect-balance')->assertSuccessful();

    $request = PaymentRequest::query()->where('reservation_id', $reservation->id)->latest('id')->first();

    expect($request->method)->toBe(PaymentRequest::METHOD_TRANSFER)
        ->and($request->provider)->toBeNull();
    Http::assertNothingSent();
});

/** Hilo de WhatsApp oficial; $lastInbound = cuándo escribió el huésped por última vez. */
function hiloWhatsApp(Conversation $conversation, \DateTimeInterface $lastInbound): void
{
    $link = \App\Models\Central\MetaChannelLink::create([
        'tenant_id' => (string) tenant('id'),
        'type' => 'whatsapp',
        'external_id' => 'PN123',
        'waba_id' => 'WABA9',
        'access_token' => 'EAAG-token',
        'active' => true,
    ]);
    $channel = Channel::create(['property_id' => test()->property->id, 'type' => 'whatsapp', 'name' => 'WhatsApp', 'active' => true]);
    $conversation->update(['channel_id' => $channel->id, 'contact_phone' => '5216564055414']);
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => 'Ok gracias',
        'created_at' => $lastInbound,
    ]);

    // Las pruebas corren sin tenancy inicializada y MetaApi solo resuelve el
    // canal dentro de un tenant: se le entrega el link directo.
    $meta = Mockery::mock(\App\Services\Meta\MetaApi::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $meta->shouldReceive('linkForConversation')->andReturn($link);
    app()->instance(\App\Services\Meta\MetaApi::class, $meta);
}

it('fuera de la ventana de 24 h no manda WhatsApp: marca, avisa y va por correo', function () {
    \Illuminate\Support\Facades\Mail::fake();
    Http::fake();

    [$reservation, $conversation] = reservaConSaldo();
    $guest = \App\Models\Guest::create(['full_name' => 'Iris Prueba', 'email' => 'iris@example.com', 'phone' => '6564055414']);
    $reservation->update(['guest_id' => $guest->id]);
    $reservation->update(['payment_due_at' => now()->addHours(12)]);
    hiloWhatsApp($conversation, now()->subDays(10));

    $this->artisan('payments:collect-balance')->assertSuccessful();

    $reminder = $conversation->messages()->where('meta->followup', 'balance_reminder')->first();

    expect($reminder->meta['undelivered'] ?? false)->toBeTrue()
        ->and(\App\Models\StaffNotification::query()->where('title', 'Respuesta no entregada')->exists())->toBeTrue();
    Http::assertNothingSent();
    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\GuestReservationMail::class, fn ($mail) => $mail->hasTo('iris@example.com'));
});

it('dentro de la ventana de 24 h sí manda por WhatsApp', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

    [$reservation, $conversation] = reservaConSaldo();
    $reservation->update(['payment_due_at' => now()->addHours(12)]);
    hiloWhatsApp($conversation, now()->subHours(3));

    $this->artisan('payments:collect-balance')->assertSuccessful();

    $reminder = $conversation->messages()->where('meta->followup', 'balance_reminder')->first();

    expect($reminder->meta['undelivered'] ?? false)->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'PN123/messages'));
});
