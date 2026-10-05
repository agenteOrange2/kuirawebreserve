<?php

use App\Http\Controllers\Tenant\CashCutController;
use App\Models\CashCut;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Refund;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

/**
 * Arrastre de fianzas entre cortes. Reproduce el caso real de cabañas
 * (corte del 17/09): $9,500 de fianzas en efectivo se cobraron antes de un
 * corte y se devolvieron después. Sin arrastre, el corte siguiente esperaba
 * −$6,500 en el cajón; con arrastre la fianza se queda en caja y sale de ahí.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    Permission::findOrCreate('reservations.view', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reservations.view');

    $type = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $type->id]);
    $this->plan = RatePlan::factory()->create(['room_type_id' => $type->id]);
});

function carryStay(\Carbon\CarbonInterface $in, ?\Carbon\CarbonInterface $out): Stay
{
    return Stay::create([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_name' => 'Huésped',
        'check_in_at' => $in,
        'planned_end_at' => $in->copy()->addDays(2),
        'check_out_at' => $out,
        'status' => $out ? Stay::STATUS_COMPLETED : Stay::STATUS_ACTIVE,
        'amount' => 1500,
        'channel' => 'panel',
        'created_by' => test()->user->id,
    ]);
}

function carryGuarantee(Stay $stay, float $amount, \Carbon\CarbonInterface $at): Payment
{
    return Payment::create([
        'stay_id' => $stay->id,
        'amount' => $amount,
        'method' => 'cash',
        'kind' => Payment::KIND_GUARANTEE,
        'received_by' => test()->user->id,
        'paid_at' => $at,
    ]);
}

function carryRefund(Payment $payment, \Carbon\CarbonInterface $at): Refund
{
    return Refund::create([
        'payment_id' => $payment->id,
        'amount' => $payment->amount,
        'status' => Refund::STATUS_COMPLETED,
        'reason' => 'Devolución de fianza',
        'created_by' => test()->user->id,
        'refunded_at' => $at,
    ]);
}

it('la fianza cobrada antes del corte y devuelta después sale del cajón que la tiene', function () {
    $cut1From = Carbon::parse('2026-09-15 00:00');
    $cut1To = Carbon::parse('2026-09-15 18:40');
    $cut2To = Carbon::parse('2026-09-17 19:13');

    // Llegan el 15 por la tarde y salen el 16 en la mañana.
    $stay = carryStay(Carbon::parse('2026-09-15 14:00'), Carbon::parse('2026-09-16 11:00'));
    $guarantee = carryGuarantee($stay, 9500, Carbon::parse('2026-09-15 14:19'));
    carryRefund($guarantee, Carbon::parse('2026-09-16 11:05'));

    $service = app(CashCutService::class);

    $first = $service->compute($this->user, $cut1From, $cut1To, null, CashCut::SCOPE_ROOMS);
    $second = $service->compute($this->user, $cut1To, $cut2To, null, CashCut::SCOPE_ROOMS);

    // Corte 1: la fianza está en el cajón y se QUEDA (sigue por devolver).
    expect($first['expected_cash'])->toEqual(9500.0)
        ->and($first['guarantees_held'])->toEqual(9500.0)
        ->and($first['guarantees_carried'])->toEqual(0.0);

    // Corte 2: arranca con ella y la devuelve; el cajón no queda negativo.
    expect($second['guarantees_carried'])->toEqual(9500.0)
        ->and($second['guarantees_cash_out'])->toEqual(9500.0)
        ->and($second['expected_cash'])->toEqual(0.0)
        ->and($second['guarantees_held'])->toEqual(0.0);
});

it('una fianza retenida al salir deja de ser del huésped y se entrega', function () {
    $from = Carbon::parse('2026-09-20 00:00');
    $to = Carbon::parse('2026-09-22 00:00');

    // Cobrada antes del periodo, retenida en la salida (sin devolución).
    $stay = carryStay(Carbon::parse('2026-09-19 15:00'), Carbon::parse('2026-09-21 11:00'));
    carryGuarantee($stay, 1500, Carbon::parse('2026-09-19 15:05'));

    $totals = app(CashCutService::class)->compute($this->user, $from, $to, null, CashCut::SCOPE_ROOMS);

    // Estaba en caja al abrir; al cerrar ya es del hotel (no se queda).
    expect($totals['guarantees_carried'])->toEqual(1500.0)
        ->and($totals['expected_cash'])->toEqual(1500.0)
        ->and($totals['guarantees_held'])->toEqual(0.0);
});

it('el corte guardado registra lo que trae y lo que deja en caja', function () {
    $stay = carryStay(now()->subHours(3), null);
    carryGuarantee($stay, 2000, now()->subHours(2));

    $request = Request::create('/api/cash-cuts', 'POST', [
        'user_id' => $this->user->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->toIso8601String(),
        'counted_cash' => 2000,
    ]);
    $request->setUserResolver(fn () => $this->user);

    app(CashCutController::class)->store($request, app(CashCutService::class));

    $cut = CashCut::query()->latest('id')->firstOrFail();

    expect((float) $cut->expected_cash)->toEqual(2000.0)
        ->and((float) $cut->guarantees_held)->toEqual(2000.0)
        ->and((float) $cut->difference)->toEqual(0.0);
});

it('el punto de venta no arrastra fianzas', function () {
    $stay = carryStay(now()->subDays(2), null);
    carryGuarantee($stay, 800, now()->subDays(2));

    $totals = app(CashCutService::class)->compute($this->user, now()->subDay(), now(), null, CashCut::SCOPE_POS);

    expect($totals['guarantees_carried'])->toEqual(0.0)
        ->and($totals['guarantees_held'])->toEqual(0.0)
        ->and($totals['expected_cash'])->toEqual(0.0);
});
