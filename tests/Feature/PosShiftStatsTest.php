<?php

use App\Http\Controllers\Tenant\PosPageController;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Las cifras del turno que la pantalla del POS muestra arriba: si cuentan
 * ventas canceladas o de ayer, el cajero cierra el turno con un número que
 * no cuadra contra el corte.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();
    $this->user = User::factory()->create();
});

function posProps(): array
{
    $request = Request::create('/pos', 'GET');
    $request->setUserResolver(fn () => test()->user);
    app()->instance('request', $request);

    $response = app(PosPageController::class)($request);
    $prop = (new ReflectionObject($response))->getProperty('props');
    $prop->setAccessible(true);

    return $prop->getValue($response);
}

it('las cifras del turno cuentan lo de hoy y los agotados', function () {
    $coca = Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Coca', 'price' => 30, 'stock_qty' => 20,
        'track_stock' => true, 'type' => 'simple', 'active' => true,
    ]);
    Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Agua', 'price' => 20, 'stock_qty' => 0,
        'track_stock' => true, 'type' => 'simple', 'active' => true,
    ]);
    // Inactivo: no cuenta ni como producto ni como agotado.
    Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Vieja', 'price' => 10, 'stock_qty' => 0,
        'track_stock' => true, 'type' => 'simple', 'active' => false,
    ]);

    $hoy = Order::create([
        'property_id' => $this->property->id,
        'status' => Order::STATUS_COMPLETED,
        'payment_method' => 'cash',
        'subtotal' => 60, 'total' => 60,
    ]);
    OrderLine::create([
        'order_id' => $hoy->id, 'product_id' => $coca->id,
        'qty' => 2, 'unit_price' => 30, 'total' => 60,
    ]);

    // Cancelada de hoy: no suma.
    Order::create([
        'property_id' => $this->property->id,
        'status' => Order::STATUS_VOID,
        'payment_method' => 'cash',
        'subtotal' => 90, 'total' => 90,
    ]);

    // Cobrada ayer: no es del turno de hoy.
    $ayer = Order::create([
        'property_id' => $this->property->id,
        'status' => Order::STATUS_COMPLETED,
        'payment_method' => 'cash',
        'subtotal' => 45, 'total' => 45,
    ]);
    $ayer->forceFill(['created_at' => now()->subDay()])->save();

    $stats = posProps()['stats'];

    expect($stats['products'])->toBe(2)
        ->and($stats['out_of_stock'])->toBe(1)
        ->and($stats['orders_today'])->toBe(1)
        ->and($stats['sold_today'])->toBe(60.0);
});
