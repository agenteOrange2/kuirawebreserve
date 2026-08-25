<?php

use App\Actions\Inventory\CreateOrder;
use App\Http\Controllers\Tenant\CashCutController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Models\CashCut;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Property;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();

    Permission::findOrCreate('reservations.view', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.view');
});

function scopeProduct(): Product
{
    return Product::factory()->create([
        'property_id' => test()->property->id,
        'name' => 'Coca',
        'price' => 30,
        'stock_qty' => 20,
    ]);
}

/** Una venta POS en efectivo (60), un cobro de reserva (500) y una fianza (300). */
function seedBothDrawers(): void
{
    app(CreateOrder::class)->handle([
        'property_id' => test()->property->id,
        'payment_method' => 'cash',
        'lines' => [['product_id' => scopeProduct()->id, 'qty' => 2]],
    ], test()->user);

    Payment::create([
        'amount' => 500,
        'method' => 'cash',
        'received_by' => test()->user->id,
        'paid_at' => now(),
    ]);

    Payment::create([
        'amount' => 300,
        'method' => 'cash',
        'kind' => Payment::KIND_GUARANTEE,
        'received_by' => test()->user->id,
        'paid_at' => now(),
    ]);
}

/** Corte guardado a mano, con los agregados en cero (solo importa el periodo). */
function makeCut(string $scope, string $from, string $to, ?int $shiftId = null): CashCut
{
    return CashCut::create([
        'property_id' => test()->property->id,
        'user_id' => test()->user->id,
        'shift_id' => $shiftId,
        'scope' => $scope,
        'opened_at' => $from,
        'closed_at' => $to,
        'orders_count' => 0,
        'orders_total' => 0,
        'orders_cost' => 0,
        'payments_count' => 0,
        'payments_total' => 0,
        'cash_total' => 0,
        'card_total' => 0,
        'transfer_total' => 0,
        'grand_total' => 0,
        'expected_cash' => 0,
        'counted_cash' => null,
        'difference' => 0,
    ]);
}

function storeCut(array $data)
{
    $request = Request::create('/api/cash-cuts', 'POST', $data);
    $request->setUserResolver(fn () => test()->user);

    return app(CashCutController::class)->store($request, app(CashCutService::class));
}

it('cada ámbito cuenta solo su caja y su efectivo esperado', function () {
    seedBothDrawers();

    $service = app(CashCutService::class);
    $from = now()->subDay();
    $to = now()->addMinute();

    $rooms = $service->compute($this->user, $from, $to, null, CashCut::SCOPE_ROOMS);
    $pos = $service->compute($this->user, $from, $to, null, CashCut::SCOPE_POS);
    $all = $service->compute($this->user, $from, $to);

    // Recepción: cobros + fianza en el arqueo, sin ventas POS.
    expect($rooms['orders_count'])->toBe(0)
        ->and($rooms['payments_count'])->toBe(1)
        ->and((float) $rooms['grand_total'])->toBe(500.0)
        ->and($rooms['guarantees_count'])->toBe(1)
        ->and((float) $rooms['expected_cash'])->toBe(800.0)
        ->and(collect($rooms['sources'])->pluck('key')->all())->toBe(['payments']);

    // Punto de venta: solo la venta, sin cobros ni fianzas.
    expect($pos['orders_count'])->toBe(1)
        ->and($pos['payments_count'])->toBe(0)
        ->and((float) $pos['grand_total'])->toBe(60.0)
        ->and($pos['guarantees_count'])->toBe(0)
        ->and((float) $pos['expected_cash'])->toBe(60.0)
        ->and(collect($pos['sources'])->pluck('key')->all())->toBe(['pos']);

    // Combinado (formato histórico): sigue sumando ambos.
    expect((float) $all['grand_total'])->toBe(560.0)
        ->and((float) $all['expected_cash'])->toBe(860.0);
});

it('el mismo periodo no se corta dos veces en el mismo ámbito, pero sí en el otro', function () {
    seedBothDrawers();

    makeCut(CashCut::SCOPE_ROOMS, now()->subHours(8)->toDateTimeString(), now()->subHour()->toDateTimeString());

    $overlapping = [
        'user_id' => $this->user->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'from' => now()->subHours(2)->toDateTimeString(),
        'to' => now()->toDateTimeString(),
    ];

    expect(storeCut($overlapping)->getStatusCode())->toBe(422);

    // El otro ámbito es otra caja: el mismo rango sí se corta.
    $posCut = storeCut([...$overlapping, 'scope' => CashCut::SCOPE_POS]);

    expect($posCut->getStatusCode())->toBe(201)
        ->and(CashCut::where('scope', CashCut::SCOPE_POS)->count())->toBe(1);
});

it('un corte combinado viejo bloquea ambos ámbitos en su periodo', function () {
    makeCut(CashCut::SCOPE_ALL, now()->subHours(8)->toDateTimeString(), now()->subHour()->toDateTimeString());

    $range = [
        'user_id' => $this->user->id,
        'from' => now()->subHours(3)->toDateTimeString(),
        'to' => now()->toDateTimeString(),
    ];

    expect(storeCut([...$range, 'scope' => CashCut::SCOPE_ROOMS])->getStatusCode())->toBe(422)
        ->and(storeCut([...$range, 'scope' => CashCut::SCOPE_POS])->getStatusCode())->toBe(422);
});

it('el inicio sugerido es por ámbito: el corte de una caja no mueve a la otra', function () {
    $service = app(CashCutService::class);

    $roomsClosed = now()->subHours(3);
    makeCut(CashCut::SCOPE_ROOMS, now()->subHours(10)->toDateTimeString(), $roomsClosed->toDateTimeString());

    expect($service->defaultOpenedAt($this->user, CashCut::SCOPE_ROOMS)->toDateTimeString())
        ->toBe($roomsClosed->toDateTimeString())
        // POS no tiene cortes ni actividad: arranca en el día, no en el
        // cierre de recepción.
        ->and($service->defaultOpenedAt($this->user, CashCut::SCOPE_POS)->toDateTimeString())
        ->not->toBe($roomsClosed->toDateTimeString());

    // Un combinado viejo posterior sí mueve ambos ámbitos.
    $allClosed = now()->subHour();
    makeCut(CashCut::SCOPE_ALL, now()->subHours(2)->toDateTimeString(), $allClosed->toDateTimeString());

    expect($service->defaultOpenedAt($this->user, CashCut::SCOPE_ROOMS)->toDateTimeString())
        ->toBe($allClosed->toDateTimeString())
        ->and($service->defaultOpenedAt($this->user, CashCut::SCOPE_POS)->toDateTimeString())
        ->toBe($allClosed->toDateTimeString());
});

it('cerrar turno con auto-corte genera un corte por caja con movimiento, ligados al turno', function () {
    $shift = Shift::create([
        'property_id' => $this->property->id,
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(4),
        'opening_cash' => 500,
        'created_by' => $this->user->id,
    ]);

    seedBothDrawers(); // venta y cobros se cuelgan del turno abierto

    $request = Request::create("/api/shifts/{$shift->id}/close", 'PATCH', ['auto_cut' => true]);
    $request->setUserResolver(fn () => $this->user);

    app(ShiftController::class)->close($request, $shift, app(CashCutService::class));

    $cuts = CashCut::query()->orderBy('scope')->get();

    expect($cuts)->toHaveCount(2)
        ->and($cuts->pluck('scope')->all())->toBe([CashCut::SCOPE_POS, CashCut::SCOPE_ROOMS])
        ->and($cuts->pluck('shift_id')->unique()->all())->toBe([$shift->id])
        ->and((float) $cuts->firstWhere('scope', CashCut::SCOPE_POS)->grand_total)->toBe(60.0)
        ->and((float) $cuts->firstWhere('scope', CashCut::SCOPE_ROOMS)->grand_total)->toBe(500.0)
        // Arqueo contra el cajón completo: fondo inicial (500) + cobro en
        // efectivo (500) + fianza (300). El fondo va solo en recepción.
        ->and((float) $cuts->firstWhere('scope', CashCut::SCOPE_ROOMS)->expected_cash)->toBe(1300.0)
        ->and((float) $cuts->firstWhere('scope', CashCut::SCOPE_ROOMS)->opening_cash)->toBe(500.0)
        ->and((float) $cuts->firstWhere('scope', CashCut::SCOPE_POS)->opening_cash)->toBe(0.0)
        ->and((float) $cuts->firstWhere('scope', CashCut::SCOPE_POS)->expected_cash)->toBe(60.0);
});

it('el auto-corte salta las cajas sin movimiento y las ya cortadas a mano', function () {
    $shift = Shift::create([
        'property_id' => $this->property->id,
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(4),
        'created_by' => $this->user->id,
    ]);

    // Solo hubo un cobro de recepción (nada de POS)...
    Payment::create([
        'amount' => 500,
        'method' => 'cash',
        'received_by' => $this->user->id,
        'paid_at' => now(),
    ]);

    // ...y recepción YA se cortó a mano durante el turno.
    makeCut(CashCut::SCOPE_ROOMS, now()->subHours(5)->toDateTimeString(), now()->toDateTimeString());

    $request = Request::create("/api/shifts/{$shift->id}/close", 'PATCH', ['auto_cut' => true]);
    $request->setUserResolver(fn () => $this->user);

    app(ShiftController::class)->close($request, $shift, app(CashCutService::class));

    // No se duplicó recepción ni se creó un POS vacío.
    expect(CashCut::count())->toBe(1)
        ->and($shift->refresh()->isOpen())->toBeFalse();
});

it('el corte guardado persiste ámbito y turno, y filtra por turno con ámbito', function () {
    $shift = Shift::create([
        'property_id' => $this->property->id,
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(2),
        'created_by' => $this->user->id,
    ]);

    // Venta del turno registrada FUERA de la ventana por fechas: por turno
    // sí cuenta (misma regla que el corte combinado).
    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'payment_method' => 'cash',
        'lines' => [['product_id' => scopeProduct()->id, 'qty' => 2]],
    ], $this->user);
    \App\Models\Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(6)]);

    $response = storeCut([
        'user_id' => $this->user->id,
        'scope' => CashCut::SCOPE_POS,
        'shift_id' => $shift->id,
        'from' => now()->subHour()->toDateTimeString(),
        'to' => now()->toDateTimeString(),
    ]);

    $cut = CashCut::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($cut->scope)->toBe(CashCut::SCOPE_POS)
        ->and($cut->shift_id)->toBe($shift->id)
        ->and($cut->orders_count)->toBe(1)
        ->and((float) $cut->grand_total)->toBe(60.0);
});

it('los movimientos del periodo se listan transacción por transacción', function () {
    seedBothDrawers();

    $service = app(CashCutService::class);
    $from = now()->subDay();
    $to = now()->addMinute();

    $rooms = $service->movements($this->user, $from, $to, null, CashCut::SCOPE_ROOMS);
    $pos = $service->movements($this->user, $from, $to, null, CashCut::SCOPE_POS);
    $all = $service->movements($this->user, $from, $to);

    expect($rooms)->toHaveCount(2)
        ->and(collect($rooms)->pluck('concept')->all())->toContain('Abono de reserva')
        // La fianza se lista pero marcada como no-cobro (no es venta).
        ->and(collect($rooms)->firstWhere('concept', 'Fianza en garantía')['collected'])->toBeFalse()
        ->and($pos)->toHaveCount(1)
        ->and($pos[0]['concept'])->toStartWith('Venta POS #')
        ->and($pos[0]['method'])->toBe('Efectivo')
        ->and((float) $pos[0]['amount'])->toBe(60.0)
        ->and($all)->toHaveCount(3);
});

it('el fondo inicial del turno entra al arqueo de recepción, no al de punto de venta', function () {
    $shift = Shift::create([
        'property_id' => $this->property->id,
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(4),
        'opening_cash' => 500,
        'created_by' => $this->user->id,
    ]);

    seedBothDrawers();

    $service = app(CashCutService::class);
    $rooms = $service->compute($this->user, $shift->started_at, now(), $shift, CashCut::SCOPE_ROOMS);
    $pos = $service->compute($this->user, $shift->started_at, now(), $shift, CashCut::SCOPE_POS);

    expect((float) $rooms['opening_cash'])->toBe(500.0)
        ->and((float) $rooms['expected_cash'])->toBe(1300.0)
        ->and((float) $pos['opening_cash'])->toBe(0.0)
        ->and((float) $pos['expected_cash'])->toBe(60.0);
});

it('el corte congela la foto de pagos pendientes del momento', function () {
    $roomType = \App\Models\RoomType::factory()->create(['property_id' => $this->property->id]);
    $room = \App\Models\Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
    ]);
    $plan = \App\Models\RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
        'price' => 1200,
    ]);

    // Reserva con pago vencido que sigue debiendo todo.
    \App\Models\Reservation::create([
        'property_id' => $this->property->id,
        'room_type_id' => $roomType->id,
        'room_id' => $room->id,
        'rate_plan_id' => $plan->id,
        'guest_name' => 'Deudor Prueba',
        'num_people' => 2,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
        'status' => \App\Enums\ReservationStatus::Confirmed,
        'total_amount' => 1200,
        'payment_due_at' => now()->subHour(),
        'source_channel' => 'front_desk',
    ]);

    seedBothDrawers();

    $response = storeCut([
        'user_id' => $this->user->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'from' => now()->subHours(2)->toDateTimeString(),
        'to' => now()->toDateTimeString(),
    ]);

    $cut = CashCut::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($cut->pending_count)->toBe(1)
        ->and((float) $cut->pending_total)->toBe(1200.0)
        ->and($cut->pending_items[0]['label'])->toContain('Deudor Prueba');
});

it('el turno de otra persona no sirve para el corte propio', function () {
    $other = User::factory()->create();
    $shift = Shift::create([
        'property_id' => $this->property->id,
        'user_id' => $other->id,
        'started_at' => now()->subHours(2),
        'created_by' => $other->id,
    ]);

    $response = storeCut([
        'user_id' => $this->user->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'shift_id' => $shift->id,
        'from' => now()->subHour()->toDateTimeString(),
        'to' => now()->toDateTimeString(),
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(CashCut::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Corte en curso en JSON (panel de caja del plano)
|--------------------------------------------------------------------------
*/

function currentCash(User $user): array
{
    $request = Request::create('/api/cash-cuts/current', 'GET');
    $request->setUserResolver(fn () => $user);

    return app(CashCutController::class)
        ->current($request, app(CashCutService::class))
        ->getData(true);
}

it('el corte en curso devuelve los mismos totales que la página, por ámbito', function () {
    seedBothDrawers();

    $data = currentCash($this->user);
    $scopes = collect($data['scopes'])->keyBy('key');
    $service = app(CashCutService::class);

    $rooms = $service->openContext($this->user, CashCut::SCOPE_ROOMS);
    $esperado = $service->compute($this->user, $rooms['from'], $rooms['to'], $rooms['shift'], CashCut::SCOPE_ROOMS);

    expect($scopes->keys()->all())->toBe([CashCut::SCOPE_ROOMS, CashCut::SCOPE_POS])
        ->and((float) $scopes['rooms']['grand_total'])->toBe((float) $esperado['grand_total'])
        ->and((float) $scopes['rooms']['expected_cash'])->toBe((float) $esperado['expected_cash'])
        ->and((float) $scopes['pos']['grand_total'])->toBe(60.0)
        // El periodo también en ISO: es lo que se manda de vuelta para cerrar.
        ->and($scopes['pos']['from_iso'])->toBeString()
        ->and($scopes['pos']['to_iso'])->toBeString()
        // Sin pedir detalle NO se calculan movimientos ni pendientes: recorren
        // estancia por estancia y este endpoint se refresca solo.
        ->and($scopes['pos']['movements'])->toBeNull()
        ->and($scopes['pos']['pending'])->toBeNull()
        ->and($data['shift'])->toBeNull();
});

it('el detalle del corte en curso se calcula solo para el ámbito que se pide', function () {
    seedBothDrawers();

    $request = Request::create('/api/cash-cuts/current', 'GET', ['detail' => 'pos']);
    $request->setUserResolver(fn () => $this->user);

    $scopes = collect(
        app(CashCutController::class)
            ->current($request, app(CashCutService::class))
            ->getData(true)['scopes']
    )->keyBy('key');

    expect($scopes['pos']['movements'])->toHaveCount(1)
        ->and($scopes['pos']['movements'][0]['concept'])->toContain('Venta POS')
        ->and($scopes['pos']['pending'])->toHaveKeys(['count', 'total', 'items'])
        // El otro ámbito no paga el costo de calcularlo.
        ->and($scopes['rooms']['movements'])->toBeNull()
        ->and($scopes['rooms']['pending'])->toBeNull();
});

it('cerrar la caja desde el plano guarda el motivo y el arqueo cuando se cuenta', function () {
    seedBothDrawers();

    $service = app(CashCutService::class);
    $context = $service->openContext($this->user, CashCut::SCOPE_POS);

    $request = Request::create('/api/cash-cuts', 'POST', [
        'user_id' => $this->user->id,
        'scope' => CashCut::SCOPE_POS,
        'from' => $context['from']->toIso8601String(),
        'to' => $context['to']->toIso8601String(),
        'counted_cash' => 50,
        'notes' => 'Corte parcial: entrega a gerencia',
    ]);
    $request->setUserResolver(fn () => $this->user);

    $cut = app(CashCutController::class)->store($request, $service)->getData(true);

    expect($cut['notes'])->toBe('Corte parcial: entrega a gerencia')
        ->and((float) $cut['counted_cash'])->toBe(50.0)
        // Esperaba 60 en efectivo y contaron 50: faltan 10.
        ->and((float) $cut['difference'])->toBe(-10.0);
});

it('cerrar la caja sin contar el efectivo se guarda igual, sin arqueo', function () {
    seedBothDrawers();

    $service = app(CashCutService::class);
    $context = $service->openContext($this->user, CashCut::SCOPE_POS);

    $request = Request::create('/api/cash-cuts', 'POST', [
        'user_id' => $this->user->id,
        'scope' => CashCut::SCOPE_POS,
        'from' => $context['from']->toIso8601String(),
        'to' => $context['to']->toIso8601String(),
        'notes' => 'Cierre rápido por cambio de turno',
    ]);
    $request->setUserResolver(fn () => $this->user);

    $cut = app(CashCutController::class)->store($request, $service)->getData(true);

    expect($cut['counted_cash'])->toBeNull()
        ->and((float) $cut['difference'])->toBe(0.0)
        ->and($cut['notes'])->toBe('Cierre rápido por cambio de turno');
});

it('sin ver reservas, el corte en curso no expone la caja de recepción', function () {
    $cocina = User::factory()->create();

    $scopes = collect(currentCash($cocina)['scopes'])->pluck('key')->all();

    expect($scopes)->toBe([CashCut::SCOPE_POS]);
});

it('el corte en curso se arma sobre el turno abierto cuando lo hay', function () {
    $shift = Shift::create([
        'property_id' => $this->property->id,
        'user_id' => $this->user->id,
        'started_at' => now()->subHours(3),
        'opening_cash' => 500,
        'created_by' => $this->user->id,
    ]);

    seedBothDrawers();

    $data = currentCash($this->user);
    $rooms = collect($data['scopes'])->firstWhere('key', CashCut::SCOPE_ROOMS);

    expect($data['shift']['id'])->toBe($shift->id)
        ->and((float) $data['shift']['opening_cash'])->toBe(500.0)
        // El fondo del turno entra al cajón esperado de recepción.
        ->and((float) $rooms['expected_cash'])->toBe(1300.0);
});
