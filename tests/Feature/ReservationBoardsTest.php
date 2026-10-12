<?php

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\InHouseStaysPageController;
use App\Http\Controllers\Tenant\PendingReservationsPageController;
use App\Http\Controllers\Tenant\ReservationBoardReportController;
use App\Http\Controllers\Tenant\ReservationsPageController;
use App\Http\Controllers\Tenant\ReservationUpcomingPageController;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/*
 * Las cuatro pantallas de trabajo de reservas (operación, próximas, en casa
 * y pendientes) tras el rediseño de oct-2026: filtros que salen de las
 * cifras y las gráficas, el saldo por renglón en "en casa" y el avance del
 * día en la operación.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Doble']);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '201',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);

    Permission::findOrCreate('reservations.manage', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.manage');
});

function tableroReserva(array $overrides = []): Reservation
{
    return Reservation::create(array_replace([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped',
        'num_people' => 2,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(4)->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'total_amount' => 1000,
        'source_channel' => 'web',
        'created_by' => test()->user->id,
    ], $overrides));
}

function tableroEstancia(array $overrides = []): Stay
{
    return Stay::create(array_replace([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Alojado',
        'num_people' => 2,
        'check_in_at' => now()->subHours(3),
        'planned_end_at' => now()->addDays(3),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 1000,
        'channel' => 'walk_in',
        'created_by' => test()->user->id,
    ], $overrides));
}

/** Props de un reporte (/reservas/<pantalla>/reporte). */
function reporteProps(string $method): array
{
    $request = Request::create('/reporte', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(ReservationBoardReportController::class)->{$method}()->toResponse($request)->getData(true)['props'];
}

function tableroProps(string $controller, array $query = []): array
{
    $request = Request::create('/pagina', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app($controller)($request)->toResponse($request)->getData(true)['props'];
}

it('en casa dice cuánto debe cada estancia y filtra a los que deben', function () {
    $debe = tableroEstancia(['guest_name' => 'Debe Todo']);
    $pagada = tableroEstancia(['guest_name' => 'Ya Pagó']);
    Payment::create([
        'stay_id' => $pagada->id,
        'amount' => 1000,
        'method' => 'cash',
        'kind' => Payment::KIND_LODGING,
        'paid_at' => now(),
    ]);

    // Con reserva: manda lo pagado a la reserva, no a la estancia.
    $reserva = tableroReserva([
        'status' => ReservationStatus::CheckedIn,
        'starts_at' => now()->subDay(),
        'total_amount' => 2400,
    ]);
    Payment::create(['reservation_id' => $reserva->id, 'amount' => 400, 'method' => 'cash', 'paid_at' => now()]);
    tableroEstancia(['guest_name' => 'Con Reserva', 'reservation_id' => $reserva->id, 'channel' => 'web']);

    $props = tableroProps(InHouseStaysPageController::class);
    $filas = collect($props['stays']['data'])->keyBy('guest_name');

    expect($filas['Debe Todo']['balance'])->toEqual(1000.0)
        ->and($filas['Ya Pagó']['balance'])->toEqual(0.0)
        ->and($filas['Con Reserva']['balance'])->toEqual(2000.0)
        ->and($props['summary']['balance'])->toEqual(3000.0)
        ->and($props['summary']['with_balance'])->toBe(2)
        // La lista ya no carga las gráficas: viven en el reporte.
        ->and($props['summary'])->not->toHaveKey('channels');

    // La gráfica de canales separa lo que llegó sin reserva.
    expect(collect(reporteProps('inHouse')['summary']['channels'])->pluck('count', 'channel')->all())
        ->toEqual(['walk_in' => 2, 'web' => 1]);

    $soloDeben = tableroProps(InHouseStaysPageController::class, ['saldo' => 1]);

    expect(collect($soloDeben['stays']['data'])->pluck('guest_name')->sort()->values()->all())
        ->toBe(['Con Reserva', 'Debe Todo'])
        ->and($soloDeben['filters']['saldo'])->toBeTrue();

    expect($debe->fresh()->status)->toBe(Stay::STATUS_ACTIVE);
});

it('en casa filtra por cuándo salen y busca también en la ficha del huésped', function () {
    tableroEstancia(['guest_name' => 'Ya Debía', 'planned_end_at' => now()->subMinutes(10)]);
    tableroEstancia(['guest_name' => 'Sale Hoy', 'planned_end_at' => now()->endOfDay()->subMinute()]);
    tableroEstancia(['guest_name' => 'Sale Mañana', 'planned_end_at' => now()->addDay()->setTime(12, 0)]);
    $guest = Guest::create(['first_name' => 'Roberta', 'last_name' => 'Ficha', 'phone' => '6561239999']);
    tableroEstancia(['guest_name' => null, 'guest_id' => $guest->id, 'planned_end_at' => now()->addDays(4)]);

    $nombres = fn (array $query) => collect(tableroProps(InHouseStaysPageController::class, $query)['stays']['data'])
        ->pluck('guest_name')->all();

    expect($nombres(['salida' => 'vencida']))->toBe(['Ya Debía'])
        ->and($nombres(['salida' => 'hoy']))->toBe(['Sale Hoy'])
        ->and($nombres(['salida' => 'manana']))->toBe(['Sale Mañana'])
        // Sin nombre copiado en la estancia, el renglón lo toma de la ficha.
        ->and($nombres(['salida' => 'despues']))->toBe(['Roberta Ficha'])
        ->and($nombres(['q' => '6561239999']))->toBe(['Roberta Ficha'])
        // Un filtro inventado se ignora en vez de vaciar la lista.
        ->and($nombres(['salida' => 'ayer']))->toHaveCount(4);
});

it('próximas filtra por día y por cómo viene pagada, y grafica dos semanas', function () {
    tableroReserva(['guest_name' => 'Llega Hoy', 'starts_at' => now()->addMinutes(30), 'ends_at' => now()->addDay()]);
    tableroReserva(['guest_name' => 'Llega Mañana', 'starts_at' => now()->addDay()->setTime(15, 0), 'ends_at' => now()->addDays(2)]);
    tableroReserva(['guest_name' => 'Pagada', 'payment_status' => PaymentStatus::Paid]);
    tableroReserva([
        'guest_name' => 'Vencida',
        'payment_status' => PaymentStatus::DepositPaid,
        'payment_due_at' => now()->subHour(),
    ]);
    tableroReserva(['guest_name' => 'Lejana', 'starts_at' => now()->addDays(40), 'ends_at' => now()->addDays(41)]);

    $nombres = fn (array $query) => collect(tableroProps(ReservationUpcomingPageController::class, $query)['reservations']['data'])
        ->pluck('guest_name')->all();

    expect($nombres(['cuando' => 'hoy']))->toBe(['Llega Hoy'])
        ->and($nombres(['cuando' => 'manana']))->toBe(['Llega Mañana'])
        ->and($nombres(['cuando' => 'semana']))->toHaveCount(4)
        ->and($nombres(['pago' => 'pagada']))->toBe(['Pagada'])
        ->and($nombres(['pago' => 'vencido']))->toBe(['Vencida']);

    $list = tableroProps(ReservationUpcomingPageController::class);
    $hoy = collect($list['reservations']['data'])->firstWhere('guest_name', 'Llega Hoy');

    // Fechas en palabras y el día para agrupar la tabla.
    expect($hoy['day_key'])->toBe(now()->format('Y-m-d'))
        ->and($hoy['starts_label'])->toStartWith('Hoy ')
        ->and($hoy['span_label'])->toBe('1 noche')
        ->and($list['summary']['tomorrow'])->toBe(1)
        ->and($list['summary'])->not->toHaveKey('payments');

    $summary = reporteProps('upcoming')['summary'];

    expect($summary['days'])->toHaveCount(14)
        ->and($summary['days'][0]['label'])->toBe('Hoy')
        ->and($summary['days'][0]['count'])->toBe(1)
        ->and($summary['days'][1]['count'])->toBe(1)
        ->and($summary['days'][3]['count'])->toBe(2)
        // La vencida sale una sola vez: en "vencido", no también en anticipo.
        ->and($summary['payments'])->toBe([
            'pagada' => 1,
            'anticipo' => 0,
            'parcial' => 0,
            'sin_pago' => 3,
            'vencido' => 1,
        ]);
});

it('pendientes busca, filtra por el reloj y cuenta transferencias por verificar', function () {
    tableroReserva(['guest_name' => 'Vencido', 'status' => ReservationStatus::Pending, 'hold_expires_at' => now()->subMinutes(5)]);
    tableroReserva(['guest_name' => 'Por Vencer', 'status' => ReservationStatus::Pending, 'hold_expires_at' => now()->addMinutes(10), 'source_channel' => 'agent']);
    $conTransferencia = tableroReserva(['guest_name' => 'En Tiempo', 'status' => ReservationStatus::Pending, 'hold_expires_at' => now()->addHours(3)]);
    tableroReserva(['guest_name' => 'Sin Reloj', 'status' => ReservationStatus::Pending, 'hold_expires_at' => null]);

    PaymentRequest::create([
        'reservation_id' => $conTransferencia->id,
        'concept' => 'Anticipo',
        'amount' => 500,
        'method' => PaymentRequest::METHOD_TRANSFER,
        'status' => PaymentRequest::STATUS_PENDING,
    ]);

    $nombres = fn (array $query) => collect(tableroProps(PendingReservationsPageController::class, $query)['reservations']['data'])
        ->pluck('guest_name')->all();

    expect($nombres(['estado' => 'vencido']))->toBe(['Vencido'])
        ->and($nombres(['estado' => 'por_vencer']))->toBe(['Por Vencer'])
        ->and($nombres(['estado' => 'en_tiempo']))->toBe(['En Tiempo'])
        ->and($nombres(['estado' => 'sin_vencimiento']))->toBe(['Sin Reloj'])
        ->and($nombres(['estado' => 'transferencia']))->toBe(['En Tiempo'])
        ->and($nombres(['q' => 'Reloj']))->toBe(['Sin Reloj']);

    $summary = reporteProps('pending')['summary'];

    expect($summary['states'])->toBe([
        'vencido' => 1,
        'por_vencer' => 1,
        'en_tiempo' => 1,
        'sin_vencimiento' => 1,
    ])
        ->and($summary['transfers'])->toBe(1)
        ->and(collect($summary['channels'])->pluck('count', 'channel')->all())
        ->toEqual(['web' => 3, 'agent' => 1]);
});

it('la operación del día cuenta llegadas recibidas y salidas hechas', function () {
    // Llegan hoy: una ya recibida y una por recibir; mañana no cuenta.
    tableroReserva(['status' => ReservationStatus::CheckedIn, 'starts_at' => now()->startOfDay()->addMinute(), 'ends_at' => now()->addDay()]);
    tableroReserva(['status' => ReservationStatus::Confirmed, 'starts_at' => now()->endOfDay()->subMinute(), 'ends_at' => now()->addDays(2)]);
    tableroReserva(['status' => ReservationStatus::Confirmed, 'starts_at' => now()->addDay()->setTime(15, 0)]);

    // Sin reserva hoy, una que ya salió hoy y una que sale hoy.
    tableroEstancia(['check_in_at' => now()->startOfDay()->addMinute(), 'planned_end_at' => now()->endOfDay()->subMinute()]);
    tableroEstancia([
        'status' => Stay::STATUS_COMPLETED,
        'check_in_at' => now()->subDay(),
        'planned_end_at' => now()->startOfDay()->addMinute(),
        'check_out_at' => now()->startOfDay()->addMinute(),
    ]);

    $props = reporteProps('operation');

    expect($props['day'])->toBe([
        'arrivals_expected' => 2,
        'arrivals_done' => 1,
        'walk_ins' => 1,
        'departures_done' => 1,
        'departures_left' => 1,
    ])
        ->and(collect($props['roomStatuses'])->sum('count'))->toBe(1);
});

it('los reportes responden en /reporte y /report redirige', function () {
    $routes = app('router')->getRoutes();

    foreach (['operacion', 'proximas', 'alojados', 'pendientes'] as $slug) {
        $redirect = $routes->match(Request::create("/reservas/{$slug}/report"));

        expect($routes->match(Request::create("/reservas/{$slug}/reporte"))->getActionName())
            ->toContain('ReservationBoardReportController')
            ->and($redirect->defaults['destination'] ?? null)->toBe("/reservas/{$slug}/reporte");
    }
});
