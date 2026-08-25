<?php

use App\Actions\Inventory\CreateOrder;
use App\Actions\Inventory\VoidOrder;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Shift;
use App\Models\Stay;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->user = User::factory()->create();
});

function posProduct(array $overrides = []): Product
{
    return Product::factory()->create(array_replace([
        'property_id' => test()->property->id,
        'name' => 'Coca',
        'price' => 30,
        'stock_qty' => 20,
    ], $overrides));
}

function openShiftFor(User $user): Shift
{
    return Shift::create([
        'property_id' => test()->property->id,
        'user_id' => $user->id,
        'started_at' => now()->subHours(2),
        'opening_cash' => 500,
        'created_by' => $user->id,
    ]);
}

it('el descuento y la propina se reflejan en el total cobrado', function () {
    $coca = posProduct();

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'discount' => 20,
        'discount_reason' => 'Cortesía',
        'tip' => 15,
        'lines' => [['product_id' => $coca->id, 'qty' => 3]],
    ], $this->user);

    // 3 × 30 = 90 de subtotal; 90 - 20 + 15 = 85 a cobrar.
    expect((float) $order->subtotal)->toBe(90.0)
        ->and((float) $order->discount)->toBe(20.0)
        ->and((float) $order->tip)->toBe(15.0)
        ->and((float) $order->total)->toBe(85.0)
        ->and($order->discount_reason)->toBe('Cortesía');
});

it('el descuento nunca deja la venta en negativo', function () {
    $coca = posProduct();

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'discount' => 500, // muy por encima del subtotal
        'lines' => [['product_id' => $coca->id, 'qty' => 1]],
    ], $this->user);

    expect((float) $order->discount)->toBe(30.0)
        ->and((float) $order->total)->toBe(0.0);
});

it('la venta se cuelga del turno abierto del encargado', function () {
    $shift = openShiftFor($this->user);
    $coca = posProduct();

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 1]],
    ], $this->user);

    expect($order->shift_id)->toBe($shift->id);
});

it('sin turno abierto la venta se registra igual, solo que sin turno', function () {
    $coca = posProduct();

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 1]],
    ], $this->user);

    expect($order->shift_id)->toBeNull()
        ->and($order->status)->toBe(Order::STATUS_COMPLETED);
});

it('el cobro también se cuelga solo del turno abierto', function () {
    $shift = openShiftFor($this->user);

    $payment = Payment::create([
        'amount' => 500,
        'method' => 'cash',
        'received_by' => $this->user->id,
        'paid_at' => now(),
    ]);

    expect($payment->shift_id)->toBe($shift->id);
});

it('cancelar una venta devuelve el producto al inventario', function () {
    $coca = posProduct(['stock_qty' => 10]);

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 3]],
    ], $this->user);

    expect((float) $coca->refresh()->stock_qty)->toBe(7.0);

    app(VoidOrder::class)->handle($order, $this->user, 'Se cobró de más');

    expect((float) $coca->refresh()->stock_qty)->toBe(10.0)
        ->and($order->refresh()->status)->toBe(Order::STATUS_VOID)
        ->and($order->void_reason)->toBe('Se cobró de más')
        ->and($order->voided_by)->toBe($this->user->id)
        ->and(StockMovement::where('type', 'void')->count())->toBe(1);
});

it('cancelar un producto compuesto devuelve cada ingrediente de su receta', function () {
    $pan = Ingredient::factory()->create(['property_id' => $this->property->id, 'stock_qty' => 20]);
    $carne = Ingredient::factory()->create(['property_id' => $this->property->id, 'stock_qty' => 10]);

    $burger = Product::factory()->composite()->create([
        'property_id' => $this->property->id,
        'name' => 'Hamburguesa',
        'price' => 120,
    ]);
    $burger->recipeItems()->create(['ingredient_id' => $pan->id, 'quantity' => 2]);
    $burger->recipeItems()->create(['ingredient_id' => $carne->id, 'quantity' => 1]);

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => $burger->id, 'qty' => 2]],
    ], $this->user);

    expect((float) $pan->refresh()->stock_qty)->toBe(16.0)
        ->and((float) $carne->refresh()->stock_qty)->toBe(8.0);

    app(VoidOrder::class)->handle($order, $this->user);

    expect((float) $pan->refresh()->stock_qty)->toBe(20.0)
        ->and((float) $carne->refresh()->stock_qty)->toBe(10.0);
});

it('una venta cancelada no se puede volver a cancelar', function () {
    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => posProduct()->id, 'qty' => 1]],
    ], $this->user);

    app(VoidOrder::class)->handle($order, $this->user);

    expect(fn () => app(VoidOrder::class)->handle($order->refresh(), $this->user))
        ->toThrow(InvalidArgumentException::class);
});

it('una venta ya liquidada en el check-out no se cancela: se reembolsa', function () {
    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => posProduct()->id, 'qty' => 1]],
    ], $this->user);

    $order->update(['settled_at' => now()]);

    expect(fn () => app(VoidOrder::class)->handle($order->refresh(), $this->user))
        ->toThrow(InvalidArgumentException::class);
});

it('la venta cancelada deja de contar en el corte de caja', function () {
    $coca = posProduct();

    $kept = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);

    $voided = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 5]],
    ], $this->user);

    app(VoidOrder::class)->handle($voided, $this->user);

    $cut = app(CashCutService::class)->compute(
        $this->user,
        now()->subDay(),
        now()->addMinute(),
    );

    expect($cut['orders_count'])->toBe(1)
        ->and((float) $cut['orders_total'])->toBe((float) $kept->total)
        ->and((float) $cut['expected_cash'])->toBe((float) $kept->total);
});

it('el corte por turno toma las ventas del turno, no las de la hora', function () {
    $shift = openShiftFor($this->user);
    $coca = posProduct();

    // Venta del turno, pero registrada FUERA de la ventana que se pediría
    // por fechas: por turno sí debe contar.
    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);
    // Por query builder: created_at no es asignable en masa.
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(6)]);

    $byClock = app(CashCutService::class)->compute(
        $this->user,
        now()->subHours(2),
        now(),
    );

    $byShift = app(CashCutService::class)->compute(
        $this->user,
        now()->subHours(2),
        now(),
        $shift,
    );

    expect($byClock['orders_count'])->toBe(0)
        ->and($byShift['orders_count'])->toBe(1)
        ->and((float) $byShift['orders_total'])->toBe(60.0);
});

/*
|--------------------------------------------------------------------------
| Cobrar en el momento un consumo de habitación (panel del plano)
|--------------------------------------------------------------------------
| La caseta del motel entrega y cobra en la puerta: la venta conserva su
| habitación —para el historial del cuarto y del vehículo— pero no se carga
| al folio. Lo gobierna `charge_to_room`, y su ausencia NO cambia nada.
*/

function stayForCharge(): Stay
{
    $roomType = RoomType::factory()->create(['property_id' => test()->property->id]);
    $room = Room::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => $roomType->id,
        'status' => 'occupied',
    ]);
    $plan = RatePlan::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => $roomType->id,
    ]);

    return Stay::create([
        'room_id' => $room->id,
        'rate_plan_id' => $plan->id,
        'guest_name' => 'Huésped en casa',
        'num_people' => 1,
        'check_in_at' => now()->subHour(),
        'planned_end_at' => now()->addHours(3),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 800,
        'channel' => 'walk_in',
    ]);
}

it('sin la bandera, el consumo de una habitación se sigue cargando al folio', function () {
    $stay = stayForCharge();
    $coca = posProduct();

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        // El POS manda SIEMPRE payment_method: honrarlo aquí habría cambiado
        // en silencio el flujo de los hoteles que hoy cargan a habitación.
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);

    expect($order->payment_method)->toBe('room')
        ->and($order->stay_id)->toBe($stay->id)
        ->and((float) $stay->fresh()->folio()['consumption_pending'])->toBe(60.0);
});

it('cobrado al momento conserva la habitación y no toca el folio', function () {
    $stay = stayForCharge();
    $coca = posProduct();

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'charge_to_room' => false,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);

    expect($order->payment_method)->toBe('cash')
        ->and($order->stay_id)->toBe($stay->id)
        // NO se sella: settled_at significa "se liquidó en el check-out" y
        // VoidOrder lo usa para prohibir cancelar.
        ->and($order->settled_at)->toBeNull()
        ->and((float) $stay->fresh()->folio()['consumption_pending'])->toBe(0.0);
});

it('lo cobrado al momento entra al corte de caja', function () {
    $stay = stayForCharge();
    $coca = posProduct();

    app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'charge_to_room' => false,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);

    $cut = app(CashCutService::class)->compute(
        $this->user,
        now()->subDay(),
        now()->addMinute(),
    );

    expect((float) $cut['cash_total'])->toBe(60.0)
        ->and((float) $cut['expected_cash'])->toBe(60.0);
});

it('lo cargado a la habitación NO entra al corte hasta el check-out', function () {
    $stay = stayForCharge();
    $coca = posProduct();

    app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);

    $cut = app(CashCutService::class)->compute(
        $this->user,
        now()->subDay(),
        now()->addMinute(),
    );

    expect((float) $cut['cash_total'])->toBe(0.0);
});

it('la venta cobrada al momento sí se puede cancelar', function () {
    $stay = stayForCharge();
    $coca = posProduct();

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'charge_to_room' => false,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);

    app(VoidOrder::class)->handle($order, $this->user);

    expect($order->fresh()->isVoid())->toBeTrue()
        ->and((float) $coca->fresh()->stock_qty)->toBe(20.0);
});

it('el catálogo del panel trae solo lo que se puede vender', function () {
    posProduct(['name' => 'Coca', 'category' => 'Bebidas']);
    posProduct(['name' => 'Descontinuado', 'category' => 'Bebidas', 'active' => false]);

    $data = app(\App\Http\Controllers\Tenant\OrderController::class)
        ->catalog()->getData(true);

    expect($data['products'])->toHaveCount(1)
        ->and($data['products'][0]['name'])->toBe('Coca')
        // Mismas llaves que la página del POS: si divergen, la caseta cobra
        // un precio distinto al del mostrador.
        ->and(array_keys($data['products'][0]))
        ->toBe(['id', 'name', 'category', 'type', 'price', 'track_stock', 'stock_qty', 'photo'])
        ->and($data['categories'])->toBe(['Bebidas']);
});

it('el folio de la estancia trae TODO lo consumido y lo ya pagado', function () {
    $stay = stayForCharge();
    $coca = posProduct();

    // Una cobrada al momento y una cargada a la cuenta.
    app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'charge_to_room' => false,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ], $this->user);

    app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 1]],
    ], $this->user);

    Payment::create([
        'stay_id' => $stay->id,
        'amount' => 300,
        'method' => 'card',
        'kind' => Payment::KIND_LODGING,
        'reference' => 'AUTH-1',
        'received_by' => $this->user->id,
        'paid_at' => now(),
    ]);

    $request = Request::create("/api/stays/{$stay->id}/folio", 'GET');
    $request->setUserResolver(fn () => $this->user);

    $folio = app(\App\Http\Controllers\Tenant\StayController::class)
        ->folio($stay)->getData(true);

    // `orders` es lo que falta cobrar (cargos a habitación sin liquidar);
    // `consumption` es todo lo entregado, cobrado o no.
    expect($folio['orders'])->toHaveCount(1)
        ->and($folio['consumption'])->toHaveCount(2)
        ->and(collect($folio['consumption'])->pluck('settled')->all())->toContain(true, false)
        ->and(collect($folio['consumption'])->firstWhere('settled', true)['method_label'])->toBe('Efectivo')
        ->and(collect($folio['consumption'])->firstWhere('settled', false)['method_label'])->toBe('A la cuenta')
        // Se puede cancelar mientras no esté liquidada en el check-out.
        ->and(collect($folio['consumption'])->every(fn (array $o) => $o['can_void']))->toBeTrue()
        // Y lo ya pagado, con su método y referencia.
        ->and($folio['payments'])->toHaveCount(1)
        ->and($folio['payments'][0]['kind_label'])->toBe('Hospedaje')
        ->and($folio['payments'][0]['method_label'])->toBe('Tarjeta')
        ->and($folio['payments'][0]['reference'])->toBe('AUTH-1');
});
