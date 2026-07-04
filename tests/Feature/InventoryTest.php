<?php

use App\Actions\Inventory\CreateOrder;
use App\Actions\Inventory\RecordStockMovement;
use App\Exceptions\InsufficientStockException;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StockMovement;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
});

it('registra compras y actualiza stock y costo', function () {
    $coca = Product::factory()->create(['property_id' => $this->property->id, 'name' => 'Coca', 'price' => 30, 'cost' => 10]);

    app(RecordStockMovement::class)->handle($coca, 'purchase', 24, 12.50);

    $coca->refresh();
    expect((float) $coca->stock_qty)->toBe(24.0)
        ->and((float) $coca->cost)->toBe(12.50)
        ->and(StockMovement::count())->toBe(1);
});

it('vende un producto simple descontando su stock', function () {
    $coca = Product::factory()->create(['property_id' => $this->property->id, 'name' => 'Coca', 'price' => 30, 'stock_qty' => 10]);

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 3]],
    ]);

    expect((float) $order->total)->toBe(90.0)
        ->and((float) $coca->refresh()->stock_qty)->toBe(7.0)
        ->and(StockMovement::where('type', 'sale')->count())->toBe(1);
});

it('explota la receta del producto compuesto (BOM)', function () {
    $pan = Ingredient::factory()->create(['property_id' => $this->property->id, 'name' => 'Pan', 'stock_qty' => 20, 'cost' => 8]);
    $carne = Ingredient::factory()->create(['property_id' => $this->property->id, 'name' => 'Carne', 'stock_qty' => 10, 'cost' => 25]);

    $burger = Product::factory()->composite()->create(['property_id' => $this->property->id, 'name' => 'Hamburguesa', 'price' => 120]);
    $burger->recipeItems()->create(['ingredient_id' => $pan->id, 'quantity' => 2]);
    $burger->recipeItems()->create(['ingredient_id' => $carne->id, 'quantity' => 1]);

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => $burger->id, 'qty' => 2]],
    ]);

    // 2 hamburguesas: 4 panes y 2 carnes menos; COGS = 2×(2×8 + 1×25) = 82.
    expect((float) $pan->refresh()->stock_qty)->toBe(16.0)
        ->and((float) $carne->refresh()->stock_qty)->toBe(8.0)
        ->and((float) $order->total)->toBe(240.0)
        ->and((float) $order->total_cost)->toBe(82.0);
});

it('rechaza la venta cuando no alcanza el stock', function () {
    $pan = Ingredient::factory()->create(['property_id' => $this->property->id, 'name' => 'Pan', 'stock_qty' => 1]);
    $burger = Product::factory()->composite()->create(['property_id' => $this->property->id, 'name' => 'Hamburguesa', 'price' => 120]);
    $burger->recipeItems()->create(['ingredient_id' => $pan->id, 'quantity' => 2]);

    app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'lines' => [['product_id' => $burger->id, 'qty' => 1]],
    ]);
})->throws(InsufficientStockException::class);

it('la venta fallida no deja rastro (transacción)', function () {
    $coca = Product::factory()->create(['property_id' => $this->property->id, 'name' => 'Coca', 'price' => 30, 'stock_qty' => 5]);
    $pan = Ingredient::factory()->create(['property_id' => $this->property->id, 'name' => 'Pan', 'stock_qty' => 0]);
    $burger = Product::factory()->composite()->create(['property_id' => $this->property->id, 'name' => 'Hamburguesa', 'price' => 120]);
    $burger->recipeItems()->create(['ingredient_id' => $pan->id, 'quantity' => 1]);

    try {
        app(CreateOrder::class)->handle([
            'property_id' => $this->property->id,
            'lines' => [
                ['product_id' => $coca->id, 'qty' => 2],
                ['product_id' => $burger->id, 'qty' => 1],
            ],
        ]);
    } catch (InsufficientStockException) {
        // esperado
    }

    expect(\App\Models\Order::count())->toBe(0)
        ->and((float) $coca->refresh()->stock_qty)->toBe(5.0)
        ->and(StockMovement::count())->toBe(0);
});

it('carga la venta a una estancia activa', function () {
    $roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $roomType->id]);
    $stay = Stay::create([
        'room_id' => $room->id,
        'rate_plan_id' => $plan->id,
        'check_in_at' => now(),
        'planned_end_at' => now()->addDay(),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 650,
    ]);

    $coca = Product::factory()->create(['property_id' => $this->property->id, 'name' => 'Coca', 'price' => 30, 'stock_qty' => 5]);

    $order = app(CreateOrder::class)->handle([
        'property_id' => $this->property->id,
        'stay_id' => $stay->id,
        'lines' => [['product_id' => $coca->id, 'qty' => 2]],
    ]);

    expect($order->stay_id)->toBe($stay->id);
});

it('marca stock bajo según punto de reorden', function () {
    $pan = Ingredient::factory()->create(['property_id' => $this->property->id, 'stock_qty' => 3, 'reorder_point' => 5]);
    $carne = Ingredient::factory()->create(['property_id' => $this->property->id, 'stock_qty' => 10, 'reorder_point' => 5]);

    expect($pan->isLowStock())->toBeTrue()
        ->and($carne->isLowStock())->toBeFalse();
});
