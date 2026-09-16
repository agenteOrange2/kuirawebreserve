<?php

use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\DashboardController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(function () {
    // Hora fija: las reservas de prueba llegan a las 15:00 y el periodo
    // "hoy" va de las 00:00 a AHORA, así que corriendo antes de esa hora la
    // llegada quedaba en el futuro y el test fallaba por el reloj, no por
    // el código (fallaba media jornada, cada día).
    $this->travelTo(now()->startOfDay()->addHours(20));

    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->rooms = Room::factory()->count(2)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->user = User::factory()->create();
});

function dashboardProps(array $query = []): array
{
    $request = Request::create('/dashboard', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(DashboardController::class)($request)
        ->toResponse($request)->getData(true)['props'];
}

function metric(array $props, string $title): array
{
    return collect($props['metrics'])->firstWhere('title', $title);
}

it('las métricas de hoy usan la contabilidad de los cortes', function () {
    // Hoy: abono de reserva 500, fianza 200 (no es ingreso), consumo de
    // folio 100, venta POS directa 150 y una orden cargada a habitación 80
    // (esa suma solo vía el pago de consumo, no dos veces).
    Payment::create(['amount' => 500, 'method' => 'cash', 'paid_at' => now()]);
    Payment::create(['amount' => 200, 'method' => 'cash', 'kind' => Payment::KIND_GUARANTEE, 'paid_at' => now()]);
    Payment::create(['amount' => 100, 'method' => 'card', 'kind' => Payment::KIND_CONSUMPTION, 'paid_at' => now()]);
    Order::create(['property_id' => test()->property->id, 'status' => Order::STATUS_COMPLETED, 'payment_method' => 'cash', 'subtotal' => 150, 'total' => 150]);
    Order::create(['property_id' => test()->property->id, 'status' => Order::STATUS_COMPLETED, 'payment_method' => 'room', 'subtotal' => 80, 'total' => 80]);

    // Ayer: un abono que NO debe aparecer en "hoy".
    Payment::create(['amount' => 300, 'method' => 'cash', 'paid_at' => now()->subDay()]);

    $props = dashboardProps();

    expect($props['filters']['range'])->toBe('today')
        ->and($props['hero']['period'])->toBe('hoy')
        ->and(metric($props, 'Ingresos')['value'])->toBe('$750')
        ->and(metric($props, 'Hospedaje cobrado')['value'])->toBe('$500')
        ->and(metric($props, 'Consumo y POS')['value'])->toBe('$250');
});

it('el rango personalizado acota métricas y el cambio compara contra el periodo anterior', function () {
    // Anteayer 150, ayer 300: al filtrar "ayer" el cambio es +100%.
    Payment::create(['amount' => 150, 'method' => 'cash', 'paid_at' => now()->subDays(2)->setTime(12, 0)]);
    Payment::create(['amount' => 300, 'method' => 'cash', 'paid_at' => now()->subDay()->setTime(12, 0)]);

    $day = now()->subDay()->toDateString();
    $props = dashboardProps(['range' => 'custom', 'from' => $day, 'to' => $day]);

    expect($props['filters']['range'])->toBe('custom')
        ->and($props['filters']['from'])->toBe($day)
        ->and(metric($props, 'Ingresos')['value'])->toBe('$300')
        ->and(metric($props, 'Ingresos')['change'])->toBe(100);
});

it('cuenta ocupación, llegadas y canceladas del periodo', function () {
    $ratePlan = \App\Models\RatePlan::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
    ]);

    Stay::create([
        'room_id' => test()->rooms[0]->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Huésped Uno',
        'num_people' => 1,
        'check_in_at' => now()->startOfDay()->addHours(9),
        'planned_end_at' => now()->addDay(),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 650,
        'channel' => 'walk_in',
    ]);

    Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Llega Hoy',
        'num_people' => 2,
        'starts_at' => now()->setTime(15, 0),
        'ends_at' => now()->addDay()->setTime(12, 0),
        'status' => ReservationStatus::Confirmed,
        'total_amount' => 650,
    ]);
    Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Canceló',
        'num_people' => 1,
        'starts_at' => now()->setTime(15, 0),
        'ends_at' => now()->addDay()->setTime(12, 0),
        'status' => ReservationStatus::Cancelled,
        'total_amount' => 650,
    ]);

    $props = dashboardProps();

    // Las 2 habitaciones ocupadas hoy: una con estancia registrada y otra
    // solo vendida (reserva confirmada sin check-in). La cancelada no
    // ocupa nada.
    expect(metric($props, 'Ocupación promedio')['value'])->toBe('100%')
        ->and(metric($props, 'Noches vendidas')['value'])->toBe('2')
        ->and(metric($props, 'Llegadas')['value'])->toBe('1')
        ->and(metric($props, 'Canceladas')['value'])->toBe('1')
        ->and(metric($props, 'Reservas nuevas')['value'])->toBe('2')
        ->and(metric($props, 'Check-ins')['value'])->toBe('1');
});

it('un rango inválido no pasa la validación', function () {
    dashboardProps(['range' => 'siempre']);
})->throws(Illuminate\Validation\ValidationException::class);

it('la reserva vendida sin check-in ocupa, y con estancia no se cuenta dos veces', function () {
    $ratePlan = \App\Models\RatePlan::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
    ]);

    // Así opera el hotel que cobra por chat: la reserva se completa en el
    // cierre de día sin que nadie haya registrado la llegada. Antes esto
    // valía 0% de ocupación con la casa llena.
    $sinCheckIn = Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->rooms[0]->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Nadie le abrió el plano',
        'num_people' => 2,
        'starts_at' => now()->startOfDay()->addHours(14),
        'ends_at' => now()->addDay()->setTime(11, 0),
        'status' => ReservationStatus::Completed,
        'total_amount' => 650,
    ]);

    expect(metric(dashboardProps(), 'Noches vendidas')['value'])->toBe('1');

    // La misma reserva, ahora con su estancia: sigue siendo UNA noche.
    Stay::create([
        'reservation_id' => $sinCheckIn->id,
        'room_id' => test()->rooms[0]->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Nadie le abrió el plano',
        'num_people' => 2,
        'check_in_at' => now()->startOfDay()->addHours(14),
        'planned_end_at' => now()->addDay()->setTime(11, 0),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 650,
        'channel' => 'reservation',
    ]);

    expect(metric(dashboardProps(), 'Noches vendidas')['value'])->toBe('1')
        ->and(metric(dashboardProps(), 'Ocupación promedio')['value'])->toBe('50%');
});

it('la noche de salida no se cuenta como noche ocupada', function () {
    $ratePlan = \App\Models\RatePlan::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
    ]);

    // Entró ayer y se fue hoy a las 11: durmió la noche de ayer, no la de
    // hoy. Contar el día de salida inflaba las noches vendidas.
    Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Se fue en la mañana',
        'num_people' => 2,
        'starts_at' => now()->subDay()->setTime(14, 0),
        'ends_at' => now()->setTime(11, 0),
        'status' => ReservationStatus::Completed,
        'total_amount' => 650,
    ]);

    expect(metric(dashboardProps(), 'Noches vendidas')['value'])->toBe('0');
});

it('el bloque de motel que entra y sale el mismo día sí ocupa', function () {
    $ratePlan = \App\Models\RatePlan::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
    ]);

    // Tres horas por la tarde. Contar solo "sigue ahí al cerrar el día" lo
    // borraba del dashboard: en un motel son buena parte de las estancias.
    Stay::create([
        'room_id' => test()->rooms[0]->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Bloque de tres horas',
        'num_people' => 2,
        'check_in_at' => now()->startOfDay()->addHours(15),
        'planned_end_at' => now()->startOfDay()->addHours(18),
        'check_out_at' => now()->startOfDay()->addHours(18),
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 450,
        'channel' => 'walk_in',
    ]);

    // Y el que cruza la medianoche es UN uso, no dos.
    Stay::create([
        'room_id' => test()->rooms[1]->id,
        'rate_plan_id' => $ratePlan->id,
        'guest_name' => 'Cruza la medianoche',
        'num_people' => 2,
        'check_in_at' => now()->subDay()->startOfDay()->addHours(21),
        'planned_end_at' => now()->startOfDay()->addHours(2),
        'check_out_at' => now()->startOfDay()->addHours(2),
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 450,
        'channel' => 'walk_in',
    ]);

    $props = dashboardProps(['range' => 'custom', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]);

    expect(metric($props, 'Noches vendidas')['value'])->toBe('2');
});
