<?php

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\ReservationReportsController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Los reportes de /reservas/reportes tienen que CUADRAR: la fianza no es
 * ingreso, lo cargado a habitación suma una sola vez y las devoluciones se
 * restan (menos la de la fianza, que nunca entró).
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cabaña',
    ]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    // Segunda habitación que NADIE renta: el reporte de uso existe para
    // que se vea en 0%.
    $this->idleRoom = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);

    Permission::findOrCreate('reservations.view', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.view');
});

function propsDelReporte(array $query = []): array
{
    $request = Request::create('/reservas/reportes', 'GET', $query + [
        'period' => 'custom',
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(ReservationReportsController::class)($request)
        ->toResponse($request)->getData(true)['props'];
}

/** El escenario de septiembre: una reserva a medio pagar y un walk-in. */
function escenarioDeSeptiembre(): array
{
    // Reserva de $2,000 con un anticipo de $1,000: debe $1,000.
    $reservation = Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Media Cuenta',
        'num_people' => 2,
        'starts_at' => '2026-09-10 15:00:00',
        'ends_at' => '2026-09-12 12:00:00',
        'status' => ReservationStatus::Confirmed,
        'payment_status' => PaymentStatus::Partial,
        'total_amount' => 2000,
        'source_channel' => 'whatsapp',
    ]);
    // Se capturó el 1 de septiembre: 9 días de anticipación.
    $reservation->created_at = '2026-09-01 10:00:00';
    $reservation->save();

    Payment::create([
        'reservation_id' => $reservation->id,
        'amount' => 1000,
        'method' => 'transfer',
        'paid_at' => '2026-09-05 12:00:00',
    ]);

    // Cancelada: no es venta ni ocupa noches, pero se cuenta como reserva.
    Reservation::create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Se Arrepintió',
        'num_people' => 2,
        'starts_at' => '2026-09-20 15:00:00',
        'ends_at' => '2026-09-21 12:00:00',
        'status' => ReservationStatus::Cancelled,
        'total_amount' => 5000,
        'source_channel' => 'web',
    ]);

    // Walk-in de $800 liquidado en el folio, con fianza de $500.
    $stay = Stay::create([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Llegó Sin Avisar',
        'num_people' => 2,
        'check_in_at' => '2026-09-15 12:00:00',
        'planned_end_at' => '2026-09-15 18:00:00',
        'check_out_at' => '2026-09-15 18:00:00',
        'status' => Stay::STATUS_COMPLETED,
        'amount' => 800,
        'channel' => 'walk_in',
    ]);

    Payment::create([
        'stay_id' => $stay->id,
        'amount' => 800,
        'method' => 'cash',
        'kind' => Payment::KIND_LODGING,
        'paid_at' => '2026-09-15 18:00:00',
    ]);

    $guarantee = Payment::create([
        'stay_id' => $stay->id,
        'amount' => 500,
        'method' => 'cash',
        'kind' => Payment::KIND_GUARANTEE,
        'paid_at' => '2026-09-15 12:30:00',
    ]);

    // Consumo cargado a la habitación: la orden NO suma al ordenarse, suma
    // cuando el folio la liquida como pago de consumos.
    Order::create([
        'property_id' => test()->property->id,
        'stay_id' => $stay->id,
        'status' => Order::STATUS_COMPLETED,
        'payment_method' => 'room',
        'settled_at' => '2026-09-15 18:00:00',
        'subtotal' => 300,
        'total' => 300,
        'created_at' => '2026-09-15 16:00:00',
    ]);
    Payment::create([
        'stay_id' => $stay->id,
        'amount' => 300,
        'method' => 'cash',
        'kind' => Payment::KIND_CONSUMPTION,
        'paid_at' => '2026-09-15 18:00:00',
    ]);

    // Venta de mostrador cobrada en el momento.
    Order::create([
        'property_id' => test()->property->id,
        'status' => Order::STATUS_COMPLETED,
        'payment_method' => 'cash',
        'subtotal' => 150,
        'total' => 150,
        'created_at' => '2026-09-16 11:00:00',
    ]);

    return [$reservation, $stay, $guarantee];
}

it('no cuenta dos veces el consumo cargado a la habitación ni trata la fianza como ingreso', function () {
    escenarioDeSeptiembre();

    $money = propsDelReporte()['money'];

    // Hospedaje: $1,000 de la reserva + $800 del folio.
    expect($money['lodging'])->toEqual(1800.0)
        // POS: $150 de mostrador + $300 del consumo liquidado. La orden de
        // $300 cargada a habitación no se suma aparte.
        ->and($money['pos'])->toEqual(450.0)
        ->and($money['collected'])->toEqual(2250.0)
        ->and($money['net'])->toEqual(2250.0)
        // La fianza se reporta aparte, nunca dentro del ingreso.
        ->and($money['guarantees'])->toEqual(500.0)
        ->and($money['guarantees_count'])->toBe(1);
});

it('resta las devoluciones de dinero pero no la de la fianza', function () {
    [$reservation, , $guarantee] = escenarioDeSeptiembre();

    Refund::create([
        'payment_id' => $reservation->payments()->first()->id,
        'reservation_id' => $reservation->id,
        'amount' => 200,
        'status' => Refund::STATUS_COMPLETED,
        'refunded_at' => '2026-09-18 10:00:00',
    ]);
    // Devolver la fianza al salir no baja el ingreso: nunca lo subió.
    Refund::create([
        'payment_id' => $guarantee->id,
        'amount' => 500,
        'status' => Refund::STATUS_COMPLETED,
        'refunded_at' => '2026-09-16 09:00:00',
    ]);

    $money = propsDelReporte()['money'];

    expect($money['refunds'])->toEqual(200.0)
        ->and($money['collected'])->toEqual(2250.0)
        ->and($money['net'])->toEqual(2050.0);
});

it('separa lo vendido de lo cobrado y deja ver el saldo pendiente', function () {
    escenarioDeSeptiembre();

    $money = propsDelReporte()['money'];

    // Vendido: solo la reserva efectiva ($2,000) más el walk-in ($800).
    expect($money['reserved'])->toEqual(2000.0)
        ->and($money['walkin'])->toEqual(800.0)
        ->and($money['sold_value'])->toEqual(2800.0)
        ->and($money['reserved_paid'])->toEqual(1000.0)
        ->and($money['reserved_pending'])->toEqual(1000.0)
        ->and($money['reserved_paid_pct'])->toEqual(50.0)
        ->and($money['with_debt'])->toBe(1);
});

it('mide el porcentaje de uso por habitación y enseña la que no se rentó', function () {
    escenarioDeSeptiembre();

    $props = propsDelReporte();
    $occupancy = $props['occupancy'];

    // 2 habitaciones × 30 días = 60 noches disponibles. Ocupadas: 10 y 11 de
    // la reserva y 15 del walk-in.
    expect($occupancy['rooms'])->toBe(2)
        ->and($occupancy['days'])->toBe(30)
        ->and($occupancy['available'])->toBe(60)
        ->and($occupancy['occupied'])->toBe(3)
        ->and($occupancy['percent'])->toEqual(5.0)
        ->and($occupancy['uses'])->toBe(2)
        ->and($occupancy['idle_rooms'])->toBe(1);

    $rooms = collect($props['byRoom'])->keyBy('name');

    expect($rooms['101']['nights'])->toBe(3)
        ->and($rooms['101']['uses'])->toBe(2)
        ->and($rooms['101']['percent'])->toEqual(10.0)
        ->and($rooms['101']['revenue'])->toEqual(2800.0)
        ->and($rooms['102']['nights'])->toBe(0)
        ->and($rooms['102']['percent'])->toEqual(0.0);

    // Los renglones suman el total: eso es lo que "cuadra".
    expect(collect($props['byRoom'])->sum('nights'))->toBe($occupancy['occupied'])
        ->and(collect($props['byRoom'])->sum('revenue'))->toEqual($props['money']['sold_value']);
});

it('dice cuándo se hizo cada reserva, con cuánta anticipación y si se pagó', function () {
    escenarioDeSeptiembre();

    $props = propsDelReporte();
    $detail = collect($props['detail']);

    // Primero la que debe dinero.
    $first = $detail->first();
    expect($first['guest'])->toBe('Media Cuenta')
        ->and($first['created_at'])->toBe('01/09/2026 10:00')
        ->and($first['lead_days'])->toBe(9)
        ->and($first['total'])->toEqual(2000.0)
        ->and($first['paid'])->toEqual(1000.0)
        ->and($first['pending'])->toEqual(1000.0)
        ->and($first['payment_label'])->toBe('Anticipo incompleto');

    // La cancelada no arrastra saldo ni anticipación.
    $cancelled = $detail->firstWhere('status', 'cancelled');
    expect($cancelled['pending'])->toEqual(0.0)
        ->and($cancelled['lead_days'])->toBeNull();

    expect($props['kpis']['avg_lead_days'])->toBe(9)
        ->and($props['kpis']['total'])->toBe(2)
        ->and($props['kpis']['sold'])->toBe(1);

    // La anticipación se agrupa en cubetas: la de 9 días cae en "8 a 30".
    $lead = collect($props['lead'])->keyBy('label');
    expect($lead['8 a 30 días']['count'])->toBe(1)
        ->and($lead['Mismo día']['count'])->toBe(0);
});

it('el filtro por habitación acota el dinero y el uso a esa habitación', function () {
    escenarioDeSeptiembre();

    $props = propsDelReporte(['room' => test()->idleRoom->id]);

    expect($props['occupancy']['rooms'])->toBe(1)
        ->and($props['occupancy']['occupied'])->toBe(0)
        ->and($props['money']['collected'])->toEqual(0.0)
        ->and($props['kpis']['total'])->toBe(0);
});

it('el PDF se arma con las mismas cifras, sin renglones de tope', function () {
    escenarioDeSeptiembre();

    $request = Request::create('/reservas/reportes/pdf', 'GET', [
        'period' => 'custom',
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]);
    $request->setUserResolver(fn () => test()->user);

    $response = app(ReservationReportsController::class)->pdf($request);

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(strlen($response->getContent()))->toBeGreaterThan(2000);
});
