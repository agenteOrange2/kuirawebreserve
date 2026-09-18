<?php

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\PaymentsPageController;
use App\Models\CashCut;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Shift;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * /pagos dejó de ser una página de 1,654 líneas con cinco bloques apilados
 * ("está todo desorganizado y se pierde uno", 2026-09-18) y ahora es un
 * tablero con tres superficies de trabajo.
 *
 * Lo que se fija aquí es lo que el dueño mira primero: que el dinero de hoy
 * use la contabilidad de los cortes (fianza fuera, POS sin doble conteo) y
 * que cada tarjeta diga lo que hay que atender.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);

    foreach (['reservations.view', 'reservations.manage', 'orders.manage', 'properties.manage'] as $ability) {
        Permission::findOrCreate($ability, 'web');
    }

    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['reservations.view', 'reservations.manage', 'orders.manage']);
});

function propsDePagos(string $metodo = '__invoke', array $query = [], ?User $como = null): array
{
    $request = Request::create('/pagos', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => $como ?? test()->user);

    $controller = app(PaymentsPageController::class);
    $response = $metodo === '__invoke'
        ? $controller($request)
        : $controller->{$metodo}($request);

    return $response->toResponse($request)->getData(true)['props'];
}

function reservaDeCaja(array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Debe Dinero',
        'num_people' => 2,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(4)->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'payment_status' => PaymentStatus::Partial,
        'total_amount' => 2000,
        'payment_due_at' => now()->subDay(),
        'source_channel' => 'whatsapp',
    ], $overrides));
}

it('el dinero de hoy usa la contabilidad de los cortes: la fianza no es ingreso', function () {
    $reservation = reservaDeCaja();

    Payment::create([
        'reservation_id' => $reservation->id,
        'amount' => 1200,
        'method' => 'transfer',
        'paid_at' => now(),
    ]);

    $stay = Stay::create([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Con Fianza',
        'num_people' => 2,
        'check_in_at' => now(),
        'planned_end_at' => now()->addHours(4),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 800,
        'channel' => 'walk_in',
    ]);

    // Depósito en garantía: se devuelve al salir, no es ingreso.
    Payment::create([
        'stay_id' => $stay->id,
        'amount' => 500,
        'method' => 'cash',
        'kind' => Payment::KIND_GUARANTEE,
        'paid_at' => now(),
    ]);

    // Consumo cargado a la habitación: la orden no suma, el pago del folio sí.
    Order::create([
        'property_id' => test()->property->id,
        'stay_id' => $stay->id,
        'status' => Order::STATUS_COMPLETED,
        'payment_method' => 'room',
        'subtotal' => 300,
        'total' => 300,
        'created_at' => now(),
    ]);
    Payment::create([
        'stay_id' => $stay->id,
        'amount' => 300,
        'method' => 'cash',
        'kind' => Payment::KIND_CONSUMPTION,
        'paid_at' => now(),
    ]);

    $today = propsDePagos()['today'];

    expect($today['lodging'])->toEqual(1200.0)
        ->and($today['pos'])->toEqual(300.0)
        ->and($today['collected'])->toEqual(1500.0)
        ->and($today['net'])->toEqual(1500.0)
        // La fianza se reporta aparte, nunca dentro del ingreso.
        ->and($today['guarantees'])->toEqual(500.0)
        ->and($today['guarantees_count'])->toBe(1);

    // Y el desglose por método suma exactamente lo cobrado.
    $porMetodo = collect($today['by_method'])->sum('amount');
    expect($porMetodo)->toEqual(1500.0);
});

it('el tablero cuenta lo que pide atención sin entrar a las cuatro pantallas', function () {
    $reservation = reservaDeCaja();
    Payment::create([
        'reservation_id' => $reservation->id,
        'amount' => 500,
        'method' => 'transfer',
        'paid_at' => now()->subDay(),
    ]);

    // Transferencia esperando ojos desde hace tres horas. created_at no es
    // fillable: se fija después o Eloquent lo pone en "ahora".
    $pedido = PaymentRequest::create([
        'reservation_id' => $reservation->id,
        'method' => PaymentRequest::METHOD_TRANSFER,
        'status' => PaymentRequest::STATUS_PENDING,
        'concept' => PaymentRequest::CONCEPT_BALANCE,
        'amount' => 1500,
    ]);
    $pedido->created_at = now()->subHours(3);
    $pedido->save();

    Shift::create([
        'property_id' => test()->property->id,
        'user_id' => test()->user->id,
        'started_at' => now()->subHours(14),
    ]);

    $attention = propsDePagos()['attention'];

    expect($attention['queue']['count'])->toBe(1)
        // Más de dos horas esperando es alguien sin su confirmación.
        ->and($attention['queue']['stale'])->toBeTrue()
        ->and($attention['overdue']['count'])->toBe(1)
        ->and($attention['overdue']['total_label'])->toBe('$1,500.00')
        ->and($attention['shift']['open'])->toBe(1)
        // Un turno de 14 horas casi siempre es uno que nadie cerró.
        ->and($attention['shift']['stale'])->toBeTrue();
});

it('avisa cuando el último corte de caja no cuadró', function () {
    CashCut::create([
        'property_id' => test()->property->id,
        'user_id' => test()->user->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'opened_at' => now()->subHours(8),
        'closed_at' => now()->subHour(),
        'expected_cash' => 1000,
        'counted_cash' => 940,
        'difference' => -60,
    ]);

    $cuts = propsDePagos()['attention']['cuts'];

    expect($cuts['today'])->toBe(1)
        ->and($cuts['off'])->toBeTrue()
        ->and($cuts['off_label'])->toBe('$60.00');
});

it('cada superficie trae solo lo suyo, no las cinco listas de antes', function () {
    reservaDeCaja();

    $verify = propsDePagos('verify');
    $collect = propsDePagos('collect');
    $movements = propsDePagos('movements');

    expect($verify)->toHaveKeys(['queue', 'closedRequests'])
        ->and($verify)->not->toHaveKey('recentPayments')
        ->and($collect)->toHaveKeys(['overdueBalances', 'pendingLinks'])
        ->and($collect)->not->toHaveKey('queue')
        ->and($movements)->toHaveKey('recentPayments')
        ->and($movements)->not->toHaveKey('overdueBalances')
        // Las tres saben qué áreas pintar en la franja de navegación.
        ->and($verify)->toHaveKeys(['canManage', 'canCashCuts', 'canSettings']);
});

it('sin permiso de gestionar, la cola de verificación ni se abre', function () {
    $mirón = User::factory()->create();
    $mirón->givePermissionTo('reservations.view');

    expect(fn () => propsDePagos('verify', [], $mirón))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    // Pero el tablero sí lo deja entrar, sin los números que no le tocan.
    $props = propsDePagos('__invoke', [], $mirón);

    expect($props['canManage'])->toBeFalse()
        ->and($props['attention']['queue']['count'])->toBe(0);
});

it('las gráficas traen catorce días sin huecos y la mezcla por método', function () {
    $reservation = reservaDeCaja();

    // Dos días con movimiento dentro de la quincena y uno fuera.
    Payment::create([
        'reservation_id' => $reservation->id,
        'amount' => 1000,
        'method' => 'transfer',
        'paid_at' => now(),
    ]);
    Payment::create([
        'reservation_id' => $reservation->id,
        'amount' => 400,
        'method' => 'cash',
        'paid_at' => now()->subDays(3),
    ]);
    Payment::create([
        'reservation_id' => $reservation->id,
        'amount' => 9999,
        'method' => 'cash',
        'paid_at' => now()->subDays(40),
    ]);

    $metrics = propsDePagos()['metrics'];

    // Catorce cubetas siempre: los días sin movimiento van en cero para que
    // la barra no se recorra.
    expect($metrics['series'])->toHaveCount(14)
        ->and(collect($metrics['series'])->last()['total'])->toEqual(1000.0)
        ->and($metrics['total'])->toEqual(1400.0)
        ->and(collect($metrics['series'])->where('total', 0.0))->toHaveCount(12);

    // La dona reparte esos mismos $1,400, sin el pago viejo.
    expect(collect($metrics['by_method'])->sum('amount'))->toEqual(1400.0)
        ->and(collect($metrics['by_method'])->pluck('method')->all())
        ->toEqualCanonicalizing(['transfer', 'cash']);
});
