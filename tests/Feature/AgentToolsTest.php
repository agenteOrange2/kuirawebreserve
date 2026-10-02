<?php

use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Sencilla', 'capacity' => 3]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'included_occupancy' => 2,
        'extra_guest_fee' => 650,
    ]);
});

it('get_policies expone ocupación real con cobro por persona extra y liga de fotos', function () {
    $this->roomType->update(['photos_url' => 'https://mimotel.com/habitaciones/sencilla']);
    $this->room->update(['max_occupancy' => 3]);
    // Cargo opcional del cuarto (mascota): el bot debe poder citarlo.
    $this->room->update(['optional_charges' => [['concept' => 'Mascota', 'amount' => 200]]]);

    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);
    $type = collect($payload['room_types'])->firstWhere('name', $this->roomType->name);

    expect($type['occupancy']['included_guests'])->toBe(2)
        ->and($type['occupancy']['max_guests'])->toBe(3)
        ->and($type['occupancy']['extra_guest_fee'])->toEqual(650)
        ->and($type['occupancy']['extra_guest_fee_label'])->toContain('$650.00')
        ->and($type['optional_charges'][0]['concept'])->toBe('Mascota')
        ->and($type['photos_url'])->toBe('https://mimotel.com/habitaciones/sencilla');
});

it('get_policies sin cobro por persona extra deja extra_guest_fee en null', function () {
    $this->room->update(['included_occupancy' => null, 'extra_guest_fee' => null]);

    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);
    $type = collect($payload['room_types'])->firstWhere('name', $this->roomType->name);

    expect($type['occupancy']['extra_guest_fee'])->toBeNull()
        ->and($type['occupancy']['included_guests'])->toBe(3); // cae a capacity
});

it('get_policies dice cuántas habitaciones hay de cada tipo (units)', function () {
    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);
    $type = collect($payload['room_types'])->firstWhere('name', $this->roomType->name);

    // Una sola cabaña de ese tipo: el bot no puede ofrecer dos (caso real
    // cabañas 2026-08-30, "2 Cabañas Reales" con una sola existiendo).
    expect($type['units'])->toBe(1);

    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);
    expect(collect($payload['room_types'])->firstWhere('name', $this->roomType->name)['units'])->toBe(2);
});

it('get_policies expone el sitio del hotel, el mapa y los enlaces útiles', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'website' => 'https://mihotel.com',
        'maps_url' => 'https://maps.app.goo.gl/abc',
        'links' => [
            ['label' => 'Recorridos', 'url' => 'https://mihotel.com/recorridos/'],
            ['label' => 'Sin liga', 'url' => ''], // se descarta
        ],
    ])]);

    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);

    expect($payload['hotel']['website'])->toBe('https://mihotel.com')
        ->and($payload['hotel']['maps_url'])->toBe('https://maps.app.goo.gl/abc')
        ->and($payload['hotel']['links'])->toHaveCount(1)
        ->and($payload['hotel']['links'][0]['label'])->toBe('Recorridos');
});

it('get_policies incluye los recorridos activos con su liga y omite los apagados', function () {
    \App\Models\Experience::create([
        'property_id' => $this->property->id,
        'name' => 'PASEO EXTREMO',
        'description' => str_repeat('Ruta larguísima por la sierra. ', 20),
        'url' => 'https://mihotel.com/tours/paseo-extremo/',
        'duration_minutes' => 120,
        'pricing_mode' => 'flat',
        'price' => 2500,
        'min_people' => 3,
        'active' => true,
    ]);
    \App\Models\Experience::create([
        'property_id' => $this->property->id,
        'name' => 'TOUR APAGADO',
        'pricing_mode' => 'per_person',
        'price' => 500,
        'active' => false,
    ]);

    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);

    expect($payload['experiences'])->toHaveCount(1)
        ->and($payload['experiences'][0]['name'])->toBe('PASEO EXTREMO')
        ->and($payload['experiences'][0]['price_label'])->toContain('$2,500.00')
        ->and($payload['experiences'][0]['duration_label'])->toBe('2 h')
        ->and($payload['experiences'][0]['url'])->toBe('https://mihotel.com/tours/paseo-extremo/')
        // Recortada: la ficha completa vive en su página, no en el prompt.
        ->and(mb_strlen($payload['experiences'][0]['description']))->toBeLessThan(200)
        // Y la liga para apartar sale en el dominio del hotel, no en el central.
        ->and($payload['experiences_booking_url'])->toContain('/reservar/experiencias');
});

it('sin recorridos cargados, get_policies no trae el bloque (el bot no puede inventarlos)', function () {
    $payload = json_decode(app(AgentToolsController::class)->policies()->getContent(), true);

    expect($payload)->not->toHaveKey('experiences')
        ->and($payload)->not->toHaveKey('experiences_booking_url');
});

it('create_hold sugiere los recorridos solo si el hotel tiene alguno activo', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    // Fechas distintas por llamada: solo hay una habitación y el primer
    // apartado la ocupa.
    $hold = fn (int $days) => json_decode(app(AgentToolsController::class)->storeHold(
        Request::create('/agent/holds', 'POST', [
            'rate_plan_id' => $plan->id,
            'starts_at' => now()->addDays($days)->setTime(15, 0)->toIso8601String(),
            'guest_name' => 'Ana García',
        ]),
        app(CreateReservation::class),
    )->getContent(), true);

    expect($hold(2))->not->toHaveKey('experiences_hint');

    \App\Models\Experience::create([
        'property_id' => $this->property->id,
        'name' => 'PASEO BRECHAS',
        'pricing_mode' => 'flat',
        'price' => 3800,
        'active' => true,
    ]);

    expect($hold(9)['experiences_hint'])->toContain('UNA sola línea');
});

it('sin el módulo cobros el bot no ofrece pasarela, aunque quede una conectada', function () {
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
    ]]);
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => 'hotel-cobros-test', 'provider' => 'paypal', 'mode' => 'test',
        'public_key' => 'client', 'secret_key' => 'secret',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    // Tenant en memoria (mismo patrón que BookingWizardExtrasTest): "basic"
    // NO trae cobros; "pro" sí.
    $bind = function (string $planKey) {
        $tenant = new \App\Models\Tenant;
        $tenant->id = 'hotel-cobros-test';
        $tenant->plan = $planKey;
        app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
        app()->instance(\App\Models\Tenant::class, $tenant);
    };

    $options = fn (int $days) => json_decode(app(AgentToolsController::class)->storeHold(
        Request::create('/agent/holds', 'POST', [
            'rate_plan_id' => $plan->id,
            'starts_at' => now()->addDays($days)->setTime(15, 0)->toIso8601String(),
            'guest_name' => 'Ana García',
        ]),
        app(CreateReservation::class),
    )->getContent(), true)['payment_options'];

    $bind('basic');
    $sinCobros = $options(3);

    // La pasarela conectada NO se ofrece sin el módulo; la transferencia sí,
    // porque va en todos los planes.
    expect($sinCobros['pasarelas'])->toBe([])
        ->and($sinCobros['transferencia'])->toBeTrue();

    $bind('pro');
    expect($options(10)['pasarelas'])->toHaveCount(1);
});

it('crear_apartado_grupo aparta todo bajo un folio, o no aparta nada', function () {
    $suite = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Grande', 'capacity' => 6]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $suite->id]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $suite->id, 'price' => 4500]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 3000]);

    $group = fn (array $lines) => app(AgentToolsController::class)->storeGroupHold(
        Request::create('/agent/group-holds', 'POST', [
            'starts_at' => now()->addDays(4)->setTime(15, 0)->toIso8601String(),
            'ends_at' => now()->addDays(5)->setTime(12, 0)->toIso8601String(),
            'guest_name' => 'Marco Hernández',
            'lines' => $lines,
        ]),
        app(\App\Actions\Reservations\CreateGroupReservation::class),
    );

    // 2 suites + 1 sencilla = 3 habitaciones, un folio, un total.
    $ok = json_decode($group([
        ['room_type_id' => $suite->id, 'rooms' => 2],
        ['room_type_id' => $this->roomType->id, 'rooms' => 1],
    ])->getContent(), true);

    expect($ok['code'])->toStartWith('GRP-')
        ->and($ok['rooms_count'])->toBe(3)
        ->and($ok['total'])->toEqual(4500 * 2 + 3000)
        ->and($ok['status'])->toBe('pending')
        ->and(\App\Models\Reservation::count())->toBe(3);

    // Todo o nada: pedir más suites de las que existen no deja nada a medias.
    $fail = $group([['room_type_id' => $suite->id, 'rooms' => 2]]);

    expect($fail->getStatusCode())->toBe(422)
        ->and(\App\Models\Reservation::count())->toBe(3);
});

it('el cobro de un grupo es uno consolidado por su folio GRP-', function () {
    $suite = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Grande']);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $suite->id]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $suite->id, 'price' => 4500]);
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '0123', 'active' => true]],
    ])]);

    $created = json_decode(app(AgentToolsController::class)->storeGroupHold(
        Request::create('/agent/group-holds', 'POST', [
            'starts_at' => now()->addDays(6)->setTime(15, 0)->toIso8601String(),
            'ends_at' => now()->addDays(7)->setTime(12, 0)->toIso8601String(),
            'guest_name' => 'Marco Hernández',
            'lines' => [['room_type_id' => $suite->id, 'rooms' => 2]],
        ]),
        app(\App\Actions\Reservations\CreateGroupReservation::class),
    )->getContent(), true);

    $payment = json_decode(app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payment-requests', 'POST', [
            'code' => $created['code'],
            'metodo' => 'transferencia',
        ]),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    )->getContent(), true);

    expect($payment['code'])->toBe($created['code'])
        ->and($payment['method'])->toBe('transferencia')
        // UN solo cobro por las dos habitaciones, no uno por cuarto.
        ->and(\App\Models\PaymentRequest::count())->toBe(1)
        ->and($payment['bank_accounts'][0]['banco'])->toBe('BBVA')
        // Y nunca se da por pagado.
        ->and($payment['instructions'])->toContain('NUNCA');

    // Efectivo no aplica a un folio consolidado.
    expect(app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payment-requests', 'POST', [
            'code' => $created['code'],
            'metodo' => 'efectivo',
        ]),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    )->getStatusCode())->toBe(422);
});

it('el panorama nunca ofrece más unidades de las que existen', function () {
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 3000,
    ]);

    $request = Request::create('/agent/availability-overview', 'GET', [
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(4)->toDateString(),
        'guests' => 12, // pide para 12 con UNA sola habitación de 2 incluidas
    ]);

    $payload = json_decode(
        app(AgentToolsController::class)->availabilityOverview($request, app(\App\Services\AvailabilityService::class))->getContent(),
        true,
    );

    $option = collect($payload['options'])->firstWhere('room_type', $this->roomType->name);

    expect($option['units'])->toBe(1)
        ->and($option['units_available'])->toBe(1)
        ->and($payload['suggested_combination'])->toHaveCount(1)
        // Lo clave: una unidad, no seis "copias" del mismo tipo.
        ->and($payload['suggested_combination'][0]['units'])->toBe(1)
        ->and($payload['combination_covers_guests'])->toBeFalse()
        ->and($payload['note'])->toContain('no alcanza');
});

it('el panorama arma la combinación del grupo solo con habitaciones libres', function () {
    // Nombre explícito: el factory elige uno al azar entre cuatro y dos
    // tipos podían llamarse igual, con el lookup por nombre agarrando el
    // equivocado (test flaky).
    $suite = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Grande', 'capacity' => 6]);
    $free = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $suite->id,
        'included_occupancy' => 6,
    ]);
    $busy = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $suite->id,
        'included_occupancy' => 6,
    ]);

    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $suite->id, 'price' => 4500]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'price' => 3000]);

    \App\Models\RoomBlock::factory()->create([
        'room_id' => $busy->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
    ]);

    $request = Request::create('/agent/availability-overview', 'GET', [
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(4)->toDateString(),
        'guests' => 8,
    ]);

    $payload = json_decode(
        app(AgentToolsController::class)->availabilityOverview($request, app(\App\Services\AvailabilityService::class))->getContent(),
        true,
    );

    $suiteOption = collect($payload['options'])->firstWhere('room_type', $suite->name);

    expect($suiteOption['units'])->toBe(2)
        ->and($suiteOption['units_available'])->toBe(1) // la otra está bloqueada
        ->and($payload['combination_covers_guests'])->toBeTrue();

    // Y la combinación se arma con la suite libre + la sencilla, sin contar
    // dos veces la suite bloqueada.
    $combo = collect($payload['suggested_combination'])->keyBy('room_type');
    expect($combo[$suite->name]['units'])->toBe(1)
        ->and($combo)->toHaveCount(2)
        ->and($free->fresh())->not->toBeNull();
});

it('sin lugar en la fecha pedida, el panorama propone fechas cercanas verificadas', function () {
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);

    // La única habitación está bloqueada el fin de semana pedido.
    \App\Models\RoomBlock::factory()->create([
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
    ]);

    $request = Request::create('/agent/availability-overview', 'GET', [
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(4)->toDateString(),
        'guests' => 2,
    ]);

    $payload = json_decode(
        app(AgentToolsController::class)->availabilityOverview($request, app(\App\Services\AvailabilityService::class))->getContent(),
        true,
    );

    expect($payload['units_available'])->toBe(0)
        ->and($payload['alternative_dates'])->not->toBeEmpty()
        ->and($payload['note'])->toContain('alternative_dates');

    // Ninguna alternativa cae dentro del bloqueo: son fechas verificadas.
    foreach ($payload['alternative_dates'] as $alternative) {
        expect($alternative['starts_at'])->not->toBe(now()->addDays(3)->toDateString())
            ->and($alternative['units_available'])->toBeGreaterThan(0)
            ->and($alternative['starts_label'])->toBeString();
    }
});

it('create_hold devuelve price_breakdown con la misma lógica que el wizard público (spec-wizard-precios §P2)', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $request = Request::create('/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addHour()->toIso8601String(),
        'guest_name' => 'Ana García',
        'adults' => 3,
    ]);

    $response = app(AgentToolsController::class)->storeHold($request, app(\App\Actions\Reservations\CreateReservation::class));
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['total'])->toEqual(1550.0) // 900 + 650 por la 3a persona
        ->and($data['price_breakdown'])->toHaveCount(2)
        ->and($data['price_breakdown'][1]['concept'])->toContain('Personas extra')
        ->and($data['price_breakdown'][1]['amount'])->toEqual(650.0)
        ->and($data['price_breakdown'][1]['amount_label'])->toBe('$650.00');
});

it('create_hold sin cargos extra devuelve una sola línea de tarifa', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $request = Request::create('/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addHour()->toIso8601String(),
        'guest_name' => 'Ana García',
        'adults' => 1,
    ]);

    $response = app(AgentToolsController::class)->storeHold($request, app(\App\Actions\Reservations\CreateReservation::class));
    $data = $response->getData(true);

    expect($data['price_breakdown'])->toHaveCount(1)
        ->and($data['price_breakdown'][0]['amount'])->toEqual(900.0);
});

function agentHold(RatePlan $plan, int $daysAhead = 2): \App\Models\Reservation
{
    return app(\App\Actions\Reservations\CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => test()->room->id,
        'starts_at' => now()->addDays($daysAhead)->setTime(15, 0),
        'confirmed' => false,
        'source_channel' => 'agent',
        'guest_name' => 'Ana García',
    ]);
}

it('create_hold expone payment_options con los métodos reales del hotel', function () {
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
        'cash_payment_enabled' => true,
    ]]);
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'), 'provider' => 'paypal', 'mode' => 'test',
        'public_key' => 'client', 'secret_key' => 'secret',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $request = Request::create('/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addHour()->toIso8601String(),
        'guest_name' => 'Ana García',
    ]);
    $data = app(AgentToolsController::class)->storeHold($request, app(\App\Actions\Reservations\CreateReservation::class))->getData(true);

    expect($data['payment_options']['pasarelas'][0]['provider'])->toBe('paypal')
        ->and($data['payment_options']['transferencia'])->toBeTrue()
        ->and($data['payment_options']['efectivo'])->toBeTrue();
});

it('solicitar_pago respeta la elección de transferencia aunque haya pasarela conectada', function () {
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
    ]]);
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'), 'provider' => 'stripe', 'mode' => 'test',
        'public_key' => 'pk', 'secret_key' => 'sk',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(), 'active' => true,
    ]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $reservation = agentHold($plan);

    $response = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $reservation->code, 'metodo' => 'transferencia']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['method'])->toBe('transferencia')
        ->and($data['bank_accounts'])->toHaveCount(1);
});

it('solicitar_pago con efectivo extiende el apartado; con efectivo apagado explica el error', function () {
    $this->property->update(['settings' => ['cash_payment_enabled' => true]]);
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $reservation = agentHold($plan);
    $holdBefore = $reservation->hold_expires_at;

    $response = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $reservation->code, 'metodo' => 'efectivo']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['method'])->toBe('efectivo')
        ->and($reservation->fresh()->hold_expires_at->gt($holdBefore))->toBeTrue()
        ->and($reservation->fresh()->notes)->toContain('Eligió pagar en el hotel');

    // Hotel sin efectivo: el bot recibe un mensaje claro para reofrecer.
    $this->property->update(['settings' => ['cash_payment_enabled' => false]]);
    $otra = agentHold($plan, daysAhead: 5);

    $denied = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $otra->code, 'metodo' => 'efectivo']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );

    expect($denied->getStatusCode())->toBe(422);
});

it('solicitar_pago con pasarela elegida pero no conectada explica el error sin sustituir en silencio', function () {
    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $reservation = agentHold($plan);

    $response = app(AgentToolsController::class)->requestPayment(
        Request::create('/agent/payments', 'POST', ['code' => $reservation->code, 'metodo' => 'pasarela']),
        app(\App\Actions\Payments\IssuePaymentRequest::class),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('pasarela');
});

/**
 * El JSON de las herramientas se le pega entero al prompt del bot y vuelve
 * como resultado de cada llamada: escapado, el modelo lee "habitación"
 * en vez de "habitación" — más tokens por cada acento y texto sucio de
 * vuelta con los modelos chicos.
 */
it('las herramientas devuelven acentos, no escapes unicode', function () {
    $this->roomType->update(['name' => 'Habitación Jacuzzi', 'photos_url' => 'https://mimotel.com/fotos/jacuzzi']);

    $json = \App\Services\Agent\AgentBrain::readable(
        app(AgentToolsController::class)->policies()
    );

    expect($json)->toContain('Habitación Jacuzzi')
        // Ni un solo escape \uXXXX en todo el payload.
        ->and(preg_match('/\\\\u[0-9a-f]{4}/', $json))->toBe(0)
        // Las barras de las URLs tampoco se escapan.
        ->and($json)->toContain('https://mimotel.com/fotos/jacuzzi')
        ->and(str_contains($json, '\\/'))->toBeFalse();
});

it('readable no rompe una respuesta que no sea un objeto JSON', function () {
    $raw = response()->json('texto suelto');

    expect(\App\Services\Agent\AgentBrain::readable($raw))->toBe('"texto suelto"');
});

// ─────────────────────────────────────────────────────────────────────────
// Avisos obligatorios al cotizar (capacidad, plazo de liquidación y
// teléfono). El dato ya viajaba en el JSON pero el bot lo omitía, así que
// ahora va como frase hecha que solo tiene que copiar.
// ─────────────────────────────────────────────────────────────────────────

function agentAvailability(RatePlan $plan, string $starts, ?string $ends = null): array
{
    $request = Request::create('/api/agent/availability', 'GET', array_filter([
        'rate_plan_id' => $plan->id,
        'starts_at' => $starts,
        'ends_at' => $ends,
    ]));

    return app(AgentToolsController::class)
        ->availability($request, app(\App\Services\AvailabilityService::class))
        ->getData(true);
}

it('al cotizar, el aviso dice capacidad, persona extra, plazo de liquidación y teléfono', function () {
    $this->property->update(['settings' => [
        'phone' => '+526568508818',
        'balance_due_enabled' => true,
        'balance_due_value' => 7,
        'balance_due_unit' => 'day',
    ]]);
    $this->room->update(['max_occupancy' => 3]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'price' => 3500,
        'deposit_percent' => 50,
        'deposit_amount' => null,
        'active' => true,
    ]);

    $payload = agentAvailability($plan, now()->addDays(30)->toDateString(), now()->addDays(31)->toDateString());
    $notice = implode("\n", $payload['quote_notice']);

    expect($notice)->toContain('Incluye 2 personas (máximo 3)')
        // El anticipo del aviso es el MISMO que cobrará el link de pago: si
        // el hotel cuenta otra cifra en sus instrucciones, manda esta.
        ->and($notice)->toContain('anticipo de $1,750.00 (50% del total)')
        ->and($notice)->toContain('Cada persona extra cuesta $650.00 por noche')
        ->and($notice)->toContain('debe quedar liquidado a más tardar el')
        ->and($notice)->toContain('7 días antes de la llegada')
        ->and($notice)->toContain('+526568508818');
});

it('al cotizar da el total por número de personas, con la persona extra sin descuento de temporada', function () {
    // Caso Hotel México 2026-09-30: con la tarifa del mes el bot le sacó el
    // descuento también a la persona extra y cotizó menos de lo que se cobra.
    $this->room->update(['max_occupancy' => 3]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'price' => 1000,
        'active' => true,
    ]);
    $plan->seasons()->create([
        'name' => 'Estancia larga', 'kind' => 'promo', 'min_nights' => 3, 'price' => 800, 'priority' => 1, 'active' => true,
    ]);

    $payload = agentAvailability($plan, now()->addDays(30)->toDateString(), now()->addDays(33)->toDateString());

    expect($payload['total'])->toEqual(2400)
        ->and($payload['totals_by_people'])->toHaveCount(2)
        ->and($payload['totals_by_people'][1]['people'])->toBe(3)
        ->and($payload['totals_by_people'][1]['total'])->toEqual(4350)
        ->and(implode("\n", $payload['quote_notice']))
        ->toContain('2 personas: $2,400.00 ($800.00 por noche); 3 personas: $4,350.00 ($1,450.00 por noche)');
});

it('con la tarifa al 100% no habla de anticipo ni de saldo por liquidar', function () {
    // Caso Hotel México 2026-10-01: "anticipo de $590.00 (100% del total)"
    // seguido de "el pago total debe quedar liquidado antes de tu llegada".
    $this->property->update(['settings' => [
        'balance_due_enabled' => true,
        'balance_due_value' => 7,
        'balance_due_unit' => 'day',
    ]]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'price' => 590,
        'deposit_percent' => 100,
        'deposit_amount' => null,
        'active' => true,
    ]);

    $payload = agentAvailability($plan, now()->addDays(30)->toDateString(), now()->addDays(31)->toDateString());
    $notice = implode("\n", $payload['quote_notice']);

    expect($notice)->toContain('Para apartar se paga el total de $590.00 por adelantado')
        ->and($notice)->not->toContain('anticipo')
        ->and($notice)->not->toContain('liquidado');
});

it('con la llegada más próxima que el plazo, el aviso no cita una fecha ya vencida', function () {
    $this->property->update(['settings' => [
        'balance_due_enabled' => true,
        'balance_due_value' => 7,
        'balance_due_unit' => 'day',
    ]]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
        // La tarifa fija su propio plazo sin mirar el calendario: para una
        // llegada en 2 días, "una semana antes" ya pasó.
        'payment_due_value' => 1,
        'payment_due_unit' => 'week',
    ]);

    $payload = agentAvailability($plan, now()->addDays(2)->toDateString(), now()->addDays(3)->toDateString());
    $notice = implode("\n", $payload['quote_notice']);

    expect($notice)->toContain('debe quedar liquidado antes de tu llegada')
        ->and($notice)->not->toContain('a más tardar el');
});

it('el hotel que no exige pago anticipado no promete ningún plazo', function () {
    $this->property->update(['settings' => ['balance_due_enabled' => false, 'phone' => '+526568508818']]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $notice = implode("\n", agentAvailability($plan, now()->addDays(30)->toDateString())['quote_notice']);

    expect($notice)->not->toContain('liquidado')
        ->and($notice)->toContain('+526568508818');
});

it('el panorama también trae capacidad redactada y el plazo de liquidación', function () {
    $this->property->update(['settings' => [
        'phone' => '+526568508818',
        'balance_due_enabled' => true,
        'balance_due_value' => 1,
        'balance_due_unit' => 'week',
    ]]);
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $request = Request::create('/api/agent/availability-overview', 'GET', [
        'starts_at' => now()->addDays(30)->toDateString(),
        'ends_at' => now()->addDays(31)->toDateString(),
    ]);
    $payload = app(AgentToolsController::class)
        ->availabilityOverview($request, app(\App\Services\AvailabilityService::class))
        ->getData(true);

    expect($payload['options'][0]['occupancy_notice'])->toContain('Incluye 2 personas')
        ->and(implode(' ', $payload['payment_notice']))->toContain('1 semana antes de la llegada')
        ->and(implode(' ', $payload['payment_notice']))->toContain('+526568508818');
});

it('crear_apartado guarda el correo que capturó el asistente', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $request = Request::create('/api/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => now()->addDays(5)->setTime(15, 0)->toDateTimeString(),
        'guest_name' => 'Ana García',
        'guest_phone' => '6561112233',
        'guest_email' => 'ana@example.com',
    ]);

    $payload = app(AgentToolsController::class)
        ->storeHold($request, app(CreateReservation::class))
        ->getData(true);

    $reservation = \App\Models\Reservation::where('code', strtoupper($payload['code']))->firstOrFail();

    expect($reservation->guest?->email)->toBe('ana@example.com')
        // Y al confirmar también avisa hasta cuándo hay que liquidar.
        ->and($payload)->toHaveKey('payment_notice');
});

it('una fecha de un año que ya pasó se cotiza en el próximo año, nunca en el pasado', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    // Caso real cabañas 2026-09-10: el huésped dijo "3 de octubre" sin año
    // y el modelo mandó el año pasado; ahí todo sale libre.
    $arrival = now()->addDays(23);
    $payload = agentAvailability(
        $plan,
        $arrival->copy()->subYear()->toDateString(),
        $arrival->copy()->subYear()->addDays(2)->toDateString(),
    );

    expect(substr($payload['starts_at'], 0, 10))->toBe($arrival->toDateString())
        ->and(substr($payload['ends_at'], 0, 10))->toBe($arrival->copy()->addDays(2)->toDateString())
        ->and($payload['units'])->toBe(2)
        ->and($payload['date_notice'])->toContain('ya pasó')
        ->and($payload['date_notice'])->toContain((string) $arrival->year);
});

it('con fecha futura no hay date_notice ni se mueve nada', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $arrival = now()->addDays(10);
    $payload = agentAvailability($plan, $arrival->toDateString(), $arrival->copy()->addDay()->toDateString());

    expect(substr($payload['starts_at'], 0, 10))->toBe($arrival->toDateString())
        ->and($payload['date_notice'])->toBeNull();
});

it('si solo la llegada trae el año viejo, la salida que ya era futura se respeta', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $arrival = now()->addDays(23);
    $payload = agentAvailability(
        $plan,
        $arrival->copy()->subYear()->toDateString(),
        $arrival->copy()->addDays(2)->toDateString(),
    );

    expect(substr($payload['starts_at'], 0, 10))->toBe($arrival->toDateString())
        ->and(substr($payload['ends_at'], 0, 10))->toBe($arrival->copy()->addDays(2)->toDateString());
});

it('el panorama tampoco consulta el pasado', function () {
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id, 'type' => 'night', 'active' => true]);

    $arrival = now()->addDays(23);
    $request = Request::create('/agent/availability-overview', 'GET', [
        'starts_at' => $arrival->copy()->subYear()->toDateString(),
        'ends_at' => $arrival->copy()->subYear()->addDays(2)->toDateString(),
        'guests' => 2,
    ]);

    $payload = app(AgentToolsController::class)
        ->availabilityOverview($request, app(\App\Services\AvailabilityService::class))
        ->getData(true);

    expect($payload['starts_at'])->toBe($arrival->toDateString())
        ->and($payload['ends_at'])->toBe($arrival->copy()->addDays(2)->toDateString())
        ->and($payload['date_notice'])->toContain('ya pasó');
});

it('crear_apartado con el año pasado aparta en el próximo año en vez de tronar', function () {
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $arrival = now()->addDays(23)->setTime(15, 0);
    $request = Request::create('/api/agent/holds', 'POST', [
        'rate_plan_id' => $plan->id,
        'starts_at' => $arrival->copy()->subYear()->format('Y-m-d H:i'),
        'guest_name' => 'Ana García',
    ]);

    $payload = app(AgentToolsController::class)
        ->storeHold($request, app(CreateReservation::class))
        ->getData(true);

    $reservation = \App\Models\Reservation::where('code', strtoupper($payload['code']))->firstOrFail();

    expect($reservation->starts_at->toDateString())->toBe($arrival->toDateString())
        ->and($payload['date_notice'])->toContain('ya pasó');
});

it('el apartado trae la fianza de ESA reserva: una cabaña paga el monto base', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
        'guarantee_tiers' => [['from' => 2, 'amount' => 1000]],
    ])]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $payload = app(AgentToolsController::class)->storeHold(
        Request::create('/api/agent/holds', 'POST', [
            'rate_plan_id' => $plan->id,
            'starts_at' => now()->addDays(5)->setTime(15, 0)->toDateTimeString(),
            'guest_name' => 'Ana García',
        ]),
        app(CreateReservation::class),
    )->getData(true);

    expect($payload['guarantee_for_this_booking']['rooms'])->toBe(1)
        ->and($payload['guarantee_for_this_booking']['per_room'])->toEqual(1500)
        ->and($payload['guarantee_for_this_booking']['total'])->toEqual(1500)
        ->and($payload['guarantee_for_this_booking']['label'])->toContain('$1,500.00');
});

it('en un grupo de dos cabañas la fianza baja al escalón y trae el total', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'guarantee_enabled' => true,
        'guarantee_amount' => 1500,
        'guarantee_tiers' => [['from' => 2, 'amount' => 1000]],
    ])]);
    $suite = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña Grande', 'capacity' => 6]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $suite->id]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $suite->id, 'price' => 4500]);

    $payload = json_decode(app(AgentToolsController::class)->storeGroupHold(
        Request::create('/agent/group-holds', 'POST', [
            'starts_at' => now()->addDays(4)->setTime(15, 0)->toIso8601String(),
            'ends_at' => now()->addDays(5)->setTime(12, 0)->toIso8601String(),
            'guest_name' => 'Marco Hernández',
            'lines' => [['room_type_id' => $suite->id, 'rooms' => 2]],
        ]),
        app(\App\Actions\Reservations\CreateGroupReservation::class),
    )->getContent(), true);

    expect($payload['guarantee_for_this_booking']['rooms'])->toBe(2)
        ->and($payload['guarantee_for_this_booking']['per_room'])->toEqual(1000)
        ->and($payload['guarantee_for_this_booking']['total'])->toEqual(2000)
        ->and($payload['guarantee_for_this_booking']['label'])->toContain('2 habitaciones')
        ->and($payload['guarantee_for_this_booking']['label'])->toContain('$2,000.00 en total');
});

it('sin fianza activa el apartado no trae guarantee_for_this_booking', function () {
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'guarantee_enabled' => false,
    ])]);
    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $payload = app(AgentToolsController::class)->storeHold(
        Request::create('/api/agent/holds', 'POST', [
            'rate_plan_id' => $plan->id,
            'starts_at' => now()->addDays(5)->setTime(15, 0)->toDateTimeString(),
            'guest_name' => 'Ana García',
        ]),
        app(CreateReservation::class),
    )->getData(true);

    expect($payload['guarantee_for_this_booking'])->toBeNull();
});

it('un día de este mes que ya pasó se cotiza el mes que entra, no el año que entra', function () {
    // Caso real cabañas 2026-09-29 (Messenger, conv. 1631): "¿tiene
    // disponible 24 y 25?", el modelo mandó el 24 de septiembre y el
    // huésped recibió "viernes 24 de septiembre de 2027".
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-29 16:56'));

    $plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'type' => 'night',
        'active' => true,
    ]);

    $payload = agentAvailability($plan, '2026-09-24', '2026-09-25');

    expect(substr($payload['starts_at'], 0, 10))->toBe('2026-10-24')
        ->and(substr($payload['ends_at'], 0, 10))->toBe('2026-10-25')
        ->and($payload['date_notice'])->toContain('24 de octubre de 2026');

    // El 31 salta al próximo mes que lo tenga.
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-31 18:00'));
    expect(substr(agentAvailability($plan, '2026-10-30', '2026-11-01')['starts_at'], 0, 10))->toBe('2026-11-30');
});
