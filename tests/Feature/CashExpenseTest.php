<?php

use App\Http\Controllers\Tenant\CashCutController;
use App\Http\Controllers\Tenant\CashExpenseController;
use App\Models\CashCut;
use App\Models\CashExpense;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Gastos de caja: el dinero que SALE del cajón durante el turno.
 *
 * Hasta ahora el arqueo solo sabía de entradas (fondo, ventas, fianzas), así
 * que los $380 de gasolina pagados de la caja salían como faltante del
 * encargado y el corte nunca cuadraba.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();

    Permission::findOrCreate('reservations.view', 'web');
    $this->ana = User::factory()->create(['name' => 'Ana']);
    $this->ana->givePermissionTo('reservations.view');
});

function turnoAbierto(float $fondo = 1000): Shift
{
    return Shift::create([
        'property_id' => test()->property->id,
        'user_id' => test()->ana->id,
        'started_at' => now()->subHours(6),
        'opening_cash' => $fondo,
        'created_by' => test()->ana->id,
    ]);
}

function cobroEnEfectivo(float $monto, Shift $turno): Payment
{
    return Payment::create([
        'amount' => $monto,
        'method' => 'cash',
        'received_by' => test()->ana->id,
        'shift_id' => $turno->id,
        'paid_at' => now()->subHours(2),
    ]);
}

function registraGasto(array $overrides = []): Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/cash-expenses', 'POST', array_replace([
        'user_id' => test()->ana->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'category' => 'transporte',
        'concept' => 'Gasolina de la camioneta',
        'amount' => 380,
    ], $overrides));
    $request->setUserResolver(fn () => test()->ana);

    return app(CashExpenseController::class)->store($request, app(CashCutService::class));
}

function corteDe(Shift $turno, string $scope = CashCut::SCOPE_ROOMS): array
{
    return app(CashCutService::class)->compute(
        test()->ana,
        $turno->started_at,
        now(),
        $turno,
        $scope,
    );
}

it('el gasto baja el efectivo esperado, peso por peso', function () {
    $turno = turnoAbierto(fondo: 1000);
    cobroEnEfectivo(2000, $turno);

    $antes = corteDe($turno);
    expect($antes['expected_cash'])->toBe(3000.0)
        ->and($antes['expenses_total'])->toBe(0.0);

    registraGasto(['shift_id' => $turno->id]);

    $despues = corteDe($turno);

    // 1000 de fondo + 2000 cobrados − 380 de gasolina.
    expect($despues['expected_cash'])->toBe(2620.0)
        ->and($despues['expenses_total'])->toBe(380.0)
        ->and($despues['expenses_count'])->toBe(1);
});

it('sin gastos el arqueo cuadra; con el gasto capturado, también', function () {
    $turno = turnoAbierto(fondo: 1000);
    cobroEnEfectivo(2000, $turno);
    registraGasto(['shift_id' => $turno->id]);

    // El cajón físico trae 2620: el encargado no debe nada.
    $agg = corteDe($turno);

    expect(round(2620.0 - $agg['expected_cash'], 2))->toBe(0.0);
});

it('agrupa los gastos por categoría, de mayor a menor', function () {
    $turno = turnoAbierto();
    registraGasto(['shift_id' => $turno->id, 'category' => 'transporte', 'amount' => 380]);
    registraGasto(['shift_id' => $turno->id, 'category' => 'insumos', 'concept' => 'Café y azúcar', 'amount' => 900]);
    registraGasto(['shift_id' => $turno->id, 'category' => 'insumos', 'concept' => 'Servilletas', 'amount' => 100]);

    $cats = collect(corteDe($turno)['expenses_by_category']);

    expect($cats->first()['key'])->toBe('insumos')
        ->and($cats->first()['total'])->toBe(1000.0)
        ->and($cats->first()['count'])->toBe(2)
        ->and($cats->last()['key'])->toBe('transporte');
});

it('el gasto aparece en el rastro de movimientos, en negativo', function () {
    $turno = turnoAbierto();
    registraGasto(['shift_id' => $turno->id]);

    $movs = app(CashCutService::class)->movements(
        $this->ana, $turno->started_at, now(), $turno, CashCut::SCOPE_ROOMS,
    );

    $gasto = collect($movs)->firstWhere('amount', -380.0);

    expect($gasto)->not->toBeNull()
        ->and($gasto['concept'])->toBe('Gasto: Gasolina de la camioneta')
        ->and($gasto['detail'])->toContain('Gasolina y transporte')
        ->and($gasto['method'])->toBe('Efectivo (salida)');
});

it('el gasto de recepción no se cuela al corte del punto de venta', function () {
    $turno = turnoAbierto();
    registraGasto(['shift_id' => $turno->id, 'scope' => CashCut::SCOPE_ROOMS]);

    expect(corteDe($turno, CashCut::SCOPE_POS)['expenses_total'])->toBe(0.0)
        ->and(corteDe($turno, CashCut::SCOPE_ROOMS)['expenses_total'])->toBe(380.0);
});

it('al cerrar el corte, el gasto queda amarrado y ya no se borra', function () {
    $turno = turnoAbierto(fondo: 1000);
    cobroEnEfectivo(2000, $turno);
    registraGasto(['shift_id' => $turno->id]);

    $request = Request::create('/api/cash-cuts', 'POST', [
        'user_id' => $this->ana->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'shift_id' => $turno->id,
        'from' => $turno->started_at->toDateTimeString(),
        'to' => now()->toDateTimeString(),
        'counted_cash' => 2620,
    ]);
    $request->setUserResolver(fn () => $this->ana);

    $corte = json_decode(app(CashCutController::class)->store($request, app(CashCutService::class))->getContent(), true);

    expect((float) $corte['expected_cash'])->toBe(2620.0)
        ->and((float) $corte['difference'])->toBe(0.0)
        ->and((int) $corte['expenses_count'])->toBe(1)
        ->and((float) $corte['expenses_total'])->toBe(380.0);

    $gasto = CashExpense::first();
    expect($gasto->cash_cut_id)->toBe($corte['id']);

    // Y borrarlo ya no se puede: sostiene un arqueo firmado.
    $borrar = app(CashExpenseController::class)->destroy($gasto);

    expect($borrar->getStatusCode())->toBe(422)
        ->and(CashExpense::count())->toBe(1);
});

it('no se registra un gasto dentro de un periodo ya cortado', function () {
    CashCut::create([
        'property_id' => $this->property->id,
        'user_id' => $this->ana->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'opened_at' => now()->subHours(8),
        'closed_at' => now()->addMinute(),
        'expected_cash' => 0,
        'created_by' => $this->ana->id,
    ]);

    $respuesta = registraGasto();

    expect($respuesta->getStatusCode())->toBe(422)
        ->and($respuesta->getData(true)['message'])->toContain('ya está dentro de un corte cerrado');
});

it('un gasto sin corte todavía sí se puede borrar', function () {
    $turno = turnoAbierto();
    registraGasto(['shift_id' => $turno->id]);

    $borrar = app(CashExpenseController::class)->destroy(CashExpense::first());

    expect($borrar->getStatusCode())->toBe(200)
        ->and(CashExpense::count())->toBe(0);
});

// ---- Las dos pantallas tienen que contar la misma historia

it('/cortes entrega los gastos del periodo para poder corregirlos', function () {
    $turno = turnoAbierto();
    registraGasto(['shift_id' => $turno->id]);

    $request = Request::create('/cortes', 'GET', ['shift' => $turno->id, 'user' => $this->ana->id]);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => $this->ana);

    $props = app(\App\Http\Controllers\Tenant\CashCutsPageController::class)($request, app(CashCutService::class))
        ->toResponse($request)->getData(true)['props'];

    $gastos = $props['preview']['expenses'];

    expect($gastos)->toHaveCount(1)
        ->and($gastos[0]['concept'])->toBe('Gasolina de la camioneta')
        ->and($gastos[0]['category_label'])->toBe('Gasolina y transporte')
        ->and($gastos[0]['locked'])->toBeFalse()
        ->and($props['preview']['expenses_total'])->toEqual(380);
});

it('/turnos muestra el corte del turno y lo que salió de su caja', function () {
    $turno = turnoAbierto(fondo: 1000);
    cobroEnEfectivo(2000, $turno);
    registraGasto(['shift_id' => $turno->id]);

    $cierre = Request::create('/api/cash-cuts', 'POST', [
        'user_id' => $this->ana->id,
        'scope' => CashCut::SCOPE_ROOMS,
        'shift_id' => $turno->id,
        'from' => $turno->started_at->toDateTimeString(),
        'to' => now()->toDateTimeString(),
        'counted_cash' => 2500,
    ]);
    $cierre->setUserResolver(fn () => $this->ana);
    app(\App\Http\Controllers\Tenant\CashCutController::class)->store($cierre, app(CashCutService::class));

    $turno->update(['ended_at' => now()]);

    $request = Request::create('/turnos', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => $this->ana);

    $props = app(\App\Http\Controllers\Tenant\ShiftsPageController::class)($request)
        ->toResponse($request)->getData(true)['props'];

    $fila = collect($props['history'])->firstWhere('id', $turno->id);

    expect($fila['expenses_total'])->toEqual(380)
        ->and($fila['cut_pending'])->toBeFalse()
        ->and($fila['cuts'])->toHaveCount(1)
        ->and($fila['cuts'][0]['grand_total'])->toEqual(2000)
        ->and($fila['cuts'][0]['expenses_total'])->toEqual(380)
        ->and($fila['cuts'][0]['counted'])->toBeTrue()
        // Contó 2500 donde debían estar 2620: faltan 120.
        ->and($fila['cuts'][0]['difference'])->toEqual(-120);
});

it('un turno cerrado sin corte queda marcado para perseguirlo', function () {
    $turno = turnoAbierto();
    $turno->update(['ended_at' => now()]);

    $request = Request::create('/turnos', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => $this->ana);

    $props = app(\App\Http\Controllers\Tenant\ShiftsPageController::class)($request)
        ->toResponse($request)->getData(true)['props'];

    expect(collect($props['history'])->firstWhere('id', $turno->id)['cut_pending'])->toBeTrue();
});
