<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\BookingController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create([
        'settings' => [
            'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
        ],
    ]);
});

function bookingAvailability(array $params): array
{
    $request = Request::create('/api/booking/availability', 'GET', $params);

    return app(BookingController::class)->availability($request, app(\App\Services\AvailabilityService::class))->getData(true);
}

function bookingHoldRequest(array $params, array $headers = []): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/booking/holds', 'POST', $params);
    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return app(BookingController::class)->holds($request, app(\App\Actions\Reservations\CreateReservation::class));
}

function bookingHoldPayload(array $overrides = []): array
{
    return array_replace([
        'mode' => 'block',
        'arrive_at' => now()->addHour()->toIso8601String(),
        'room_type_id' => test()->roomType->id,
        'adults' => 2,
        'children' => 0,
        'guest_name' => 'Ana García',
        'guest_phone' => '5511112233',
        'rendered_at' => now()->subSeconds(5)->toIso8601String(),
        'website' => '',
    ], $overrides);
}

beforeEach(function () {
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla', 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
});

it('modo block: usa la tarifa por bloque activa y calcula el total en servidor', function () {
    RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $payload = bookingAvailability(['mode' => 'block', 'arrive_at' => now()->addHour()->toIso8601String(), 'adults' => 2]);

    expect($payload['mode'])->toBe('block')
        ->and($payload['any_available'])->toBeTrue()
        ->and($payload['options'])->toHaveCount(1)
        ->and($payload['options'][0]['total'])->toEqual(900.0)
        ->and($payload['options'][0]['available'])->toBeTrue()
        ->and($payload['options'][0]['requires_prepayment'])->toBeFalse();
});

it('sin adults en la petición, cotiza con 1 de anclaje — nunca dispara el cargo por persona extra (wizard restructurado)', function () {
    // Paso "Fechas" del wizard ya no pide personas — eso se pregunta
    // hasta el paso "Confirmar habitación". Mientras tanto, el precio
    // mostrado debe ser limpiamente "desde", sin ningún recargo colado.
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->room->update(['included_occupancy' => 1, 'extra_guest_fee' => 650]);

    $payload = bookingAvailability(['mode' => 'block', 'arrive_at' => now()->addHour()->toIso8601String()]);

    expect($payload['options'][0]['total'])->toEqual(900.0)
        ->and($payload['options'][0]['price_breakdown'])->toHaveCount(1);
});

it('room_type_id acota la respuesta a un solo tipo (re-consulta del paso "Confirmar habitación")', function () {
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $otherType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 4]);
    RatePlan::factory()->block(720, 2000)->create(['property_id' => $this->property->id, 'room_type_id' => $otherType->id]);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $otherType->id]);

    $payload = bookingAvailability([
        'mode' => 'block',
        'arrive_at' => now()->addHour()->toIso8601String(),
        'adults' => 3,
        'room_type_id' => $this->roomType->id,
    ]);

    expect($payload['options'])->toHaveCount(1)
        ->and($payload['options'][0]['room_type_id'])->toBe($this->roomType->id);
});

it('modo night: usa la tarifa por noche y calcula noches × precio', function () {
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 800,
    ]);

    $payload = bookingAvailability([
        'mode' => 'night',
        'arrive_date' => now()->addDay()->toDateString(),
        'depart_date' => now()->addDays(3)->toDateString(),
        'adults' => 1,
    ]);

    expect($payload['options'][0]['total'])->toEqual(1600.0); // 2 noches × 800
});

it('un tipo sin tarifa de esa modalidad no aparece (motel sin tarifas por noche)', function () {
    // Solo tiene tarifa por bloque — el caso real de motellacupula.
    RatePlan::factory()->block(720, 1300)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $payload = bookingAvailability([
        'mode' => 'night',
        'arrive_date' => now()->addDay()->toDateString(),
        'depart_date' => now()->addDays(2)->toDateString(),
    ]);

    expect($payload['options'])->toHaveCount(0)
        ->and($payload['any_available'])->toBeFalse();
});

it('un tipo cuya capacidad no alcanza para el grupo se marca no disponible, ya no desaparece (wizard restructurado 2026-07-11)', function () {
    // El paso "Fechas" ya no pide personas — quien pide el número real es
    // el paso "Confirmar habitación", DESPUÉS de elegir un tipo. Si en ese
    // momento no alcanza, el tipo sigue en la lista (para no desorientar
    // con una opción que estaba ahí y desaparece) pero available=false.
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    $payload = bookingAvailability(['mode' => 'block', 'arrive_at' => now()->addHour()->toIso8601String(), 'adults' => 5]);

    expect($payload['options'])->toHaveCount(1)
        ->and($payload['options'][0]['available'])->toBeFalse()
        ->and($payload['options'][0]['rooms_count'])->toBe(0)
        ->and($payload['options'][0]['effective_capacity'])->toBe(2);
});

it('holds() rechaza si adults+children excede la capacidad del tipo, aunque el precio ya se hubiera cotizado con menos gente', function () {
    // Bug real de producción (2026-07-10): el huésped cotizaba con 2
    // adultos (dentro de la capacidad 2), pero si el número cambiaba
    // DESPUÉS de elegir la habitación sin volver a buscar, holds() creaba
    // la reserva igual — cobrando un precio distinto al mostrado y
    // aceptando más gente de la que el tipo admite. availability() ya
    // filtraba por capacidad; holds() debe exigir lo mismo.
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    $response = bookingHoldRequest(bookingHoldPayload(['adults' => 5])); // capacidad del tipo es 2

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('hasta 2 personas');
    expect(Reservation::count())->toBe(0);
});

it('respeta la antelación mínima: available=false y explica por qué', function () {
    RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'min_advance_unit' => 'hour',
        'min_advance_value' => 4,
    ]);

    $payload = bookingAvailability(['mode' => 'block', 'arrive_at' => now()->addHour()->toIso8601String(), 'adults' => 1]);

    expect($payload['options'][0]['available'])->toBeFalse()
        ->and($payload['options'][0]['rooms_count'])->toBe(0)
        ->and($payload['options'][0]['advance_error'])->not->toBeNull();
});

it('el precio mostrado en disponibilidad incluye el price_modifier de la habitación e coincide con lo que cobra el hold', function () {
    // Caso real detectado en producción (motellacupula, habitación 101):
    // disponibilidad ignoraba el modificador de la habitación específica y
    // el hold cobraba de más sin avisar.
    RatePlan::factory()->block(720, 1300)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->room->update(['price_modifier' => 650]);

    $availability = bookingAvailability(['mode' => 'block', 'arrive_at' => now()->addHour()->toIso8601String(), 'adults' => 2]);
    $option = $availability['options'][0];

    expect($option['total'])->toEqual(1950.0) // 1300 + 650, no 1300 a secas
        ->and($option['room_id'])->toBe($this->room->id)
        ->and($option['price_breakdown'])->toHaveCount(2)
        ->and(array_sum(array_column($option['price_breakdown'], 'amount')))->toEqual(1950.0);

    $response = bookingHoldRequest(bookingHoldPayload(['room_id' => $option['room_id']]));
    $data = $response->getData(true);

    expect($data['total'])->toEqual($option['total']) // lo que se mostró es lo que se cobra
        ->and(array_sum(array_column($data['price_breakdown'], 'amount')))->toEqual($data['room_total']);
});

it('el desglose muestra la línea de persona extra cuando aplica (spec-wizard-precios §3)', function () {
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->roomType->update(['capacity' => 3]);
    $this->room->update(['included_occupancy' => 2, 'extra_guest_fee' => 650]);

    $availability = bookingAvailability(['mode' => 'block', 'arrive_at' => now()->addHour()->toIso8601String(), 'adults' => 3]);
    $option = $availability['options'][0];

    expect($option['total'])->toEqual(1550.0) // 900 + 650 por la 3a persona
        ->and($option['price_breakdown'])->toHaveCount(2)
        ->and($option['price_breakdown'][1]['concept'])->toContain('Personas extra')
        ->and($option['price_breakdown'][1]['amount'])->toEqual(650.0);

    $response = bookingHoldRequest(bookingHoldPayload(['adults' => 3, 'room_id' => $option['room_id']]));
    $data = $response->getData(true);

    expect($data['total'])->toEqual(1550.0)
        ->and($data['price_breakdown'])->toHaveCount(2);
});

it('un cuarto con max_occupancy propio aparece con recargo en vez de desaparecer de la búsqueda', function () {
    // Bug real reportado (2026-07-11): buscar con 2 adultos mostraba el
    // catálogo completo, pero con 3 desaparecían tipos que en realidad SÍ
    // podían admitir 3 con cargo extra — Room.max_occupancy ya existía en
    // el modelo (override propio sobre la capacidad "de catálogo" del
    // tipo) pero availability() nunca lo consultaba, solo el `capacity`
    // del tipo (2), así que el WHERE a nivel de tipo excluía el cuarto
    // entero antes de que extraChargeLines() tuviera oportunidad de
    // aplicar el recargo.
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    // roomType.capacity se queda en 2 (default del factory) — el override
    // vive en el cuarto, no en el tipo.
    $this->room->update(['max_occupancy' => 3, 'included_occupancy' => 2, 'extra_guest_fee' => 650]);

    $availability = bookingAvailability(['mode' => 'block', 'arrive_at' => now()->addHour()->toIso8601String(), 'adults' => 3]);

    expect($availability['options'])->toHaveCount(1) // antes del fix: 0
        ->and($availability['options'][0]['capacity'])->toBe(2) // capacidad "de catálogo" del tipo, sin cambios
        ->and($availability['options'][0]['effective_capacity'])->toBe(3) // techo real de ESTE cuarto
        ->and($availability['options'][0]['total'])->toEqual(1550.0); // 900 + 650 por la 3a persona
});

it('si el cuarto ofrecido ya no está libre al confirmar, rechaza en vez de cobrar otro precio en silencio', function () {
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    // Otra reserva se adelanta y toma la única habitación del tipo.
    app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => RatePlan::first()->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(13),
        'confirmed' => true,
    ]);

    $response = bookingHoldRequest(bookingHoldPayload(['room_id' => $this->room->id]));

    expect($response->getStatusCode())->toBe(422);
    expect(Reservation::where('source_channel', 'web')->count())->toBe(0);
});

it('crea el hold con el total recalculado en servidor, ignorando cualquier precio del cliente', function () {
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    // El cliente intenta mandar un total/rate_plan_id manipulado: no existen
    // como campos válidos en la request, así que se ignoran sin más.
    $response = bookingHoldRequest(bookingHoldPayload(['total' => 1, 'rate_plan_id' => 999]));

    expect($response->getStatusCode())->toBe(201);
    $data = $response->getData(true);
    expect($data['total'])->toEqual(900.0)
        ->and($data['requires_prepayment'])->toBeFalse();

    $reservation = Reservation::where('code', $data['code'])->firstOrFail();
    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->source_channel)->toBe('web')
        ->and((float) $reservation->total_amount)->toBe(900.0)
        ->and($reservation->guest_name)->toBe('Ana García');
});

it('rechaza el hold si el honeypot viene lleno (bot)', function () {
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    expect(fn () => bookingHoldRequest(bookingHoldPayload(['website' => 'http://spam.example'])))
        ->toThrow(ValidationException::class);

    expect(Reservation::count())->toBe(0);
});

it('rechaza el hold si se envió demasiado rápido (mínimo tiempo de llenado)', function () {
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    expect(fn () => bookingHoldRequest(bookingHoldPayload(['rendered_at' => now()->toIso8601String()])))
        ->toThrow(ValidationException::class);

    expect(Reservation::count())->toBe(0);
});

it('la idempotencia devuelve la misma respuesta sin crear una segunda reserva', function () {
    RatePlan::factory()->block(720, 900)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    $payload = bookingHoldPayload();
    $first = bookingHoldRequest($payload, ['Idempotency-Key' => 'abc-123']);
    $second = bookingHoldRequest($payload, ['Idempotency-Key' => 'abc-123']);

    expect(Reservation::count())->toBe(1)
        ->and($second->getData(true)['code'])->toBe($first->getData(true)['code'])
        ->and($second->headers->get('Idempotency-Replayed'))->toBe('true');
});

it('con tarifa que exige anticipo, el pago devuelve instrucciones de transferencia y extiende el hold', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'deposit_percent' => 30,
    ]);

    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);
    $originalHoldExpiry = $reservation->hold_expires_at;

    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST');
    $response = app(BookingController::class)->payment($request, $reservation->code, app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(201);
    $data = $response->getData(true);
    expect($data['method'])->toBe('transfer')
        ->and($data['bank_accounts'])->toHaveCount(1)
        ->and($reservation->refresh()->hold_expires_at->gt($originalHoldExpiry))->toBeTrue();
});

it('con varias pasarelas activas, resuelve la que el huésped eligió (spec-reservas-avanzado §1.4)', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/pay/cs_1']),
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok-1']),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-1',
            'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1']],
        ]),
    ]);

    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'), 'provider' => 'stripe', 'mode' => 'test',
        'public_key' => 'pk_1', 'secret_key' => 'sk_1',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'), 'provider' => 'paypal', 'mode' => 'test',
        'public_key' => 'client_1', 'secret_key' => 'secret_1',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);

    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'deposit_percent' => 30,
    ]);
    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);

    // Aunque Stripe fue la primera en crearse (id más bajo), el huésped
    // eligió PayPal en la pantalla de "¿cómo prefieres pagar?" — antes
    // esto era imposible de expresar, siempre ganaba la primera por id.
    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST', ['provider' => 'paypal']);
    $response = app(BookingController::class)->payment($request, $reservation->code, app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true)['provider'])->toBe('PayPal')
        ->and($response->getData(true)['checkout_url'])->toContain('paypal.com');
});

it('pedir una pasarela específica que no está conectada se rechaza en vez de usar otra en silencio', function () {
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'), 'provider' => 'stripe', 'mode' => 'test',
        'public_key' => 'pk_1', 'secret_key' => 'sk_1',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);

    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'deposit_percent' => 30,
    ]);
    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);

    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST', ['provider' => 'paypal']);
    $response = app(BookingController::class)->payment($request, $reservation->code, app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(422)
        ->and(DB::table('payment_requests')->count())->toBe(0);
});

it('sin anticipo configurado, pedir el pago se rechaza — el hotel confirma directo', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        // Sin deposit_percent: requiresPrepayment() = false.
    ]);

    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);

    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST');
    $response = app(BookingController::class)->payment($request, $reservation->code, app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('no requiere pago en línea');
    // Nunca se creó una solicitud de cobro por el total completo.
    expect(DB::table('payment_requests')->count())->toBe(0);
});

it('payment_mode "never" bloquea el pago aunque la tarifa sí tenga anticipo configurado', function () {
    $this->property->update(['settings' => ['payment_mode' => 'never']]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'deposit_percent' => 30,
    ]);

    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);

    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST');
    $response = app(BookingController::class)->payment($request, $reservation->code, app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(422);
});

it('payment_mode "always" pide pago aunque la tarifa no tenga anticipo configurado, por el total', function () {
    $this->property->update(['settings' => [
        'payment_mode' => 'always',
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
    ]]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        // Sin deposit_percent: en modo automático no pediría nada.
    ]);

    $reservation = app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);

    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST');
    $response = app(BookingController::class)->payment($request, $reservation->code, app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(201)
        ->and((float) $response->getData(true)['amount'])->toEqual(900.0); // sin anticipo = cobra el total
});

it('pedir pago de un código inexistente responde 404', function () {
    $request = Request::create('/api/booking/holds/RES-2026-9999/payment', 'POST');
    $response = app(BookingController::class)->payment($request, 'RES-2026-9999', app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(404);
});
