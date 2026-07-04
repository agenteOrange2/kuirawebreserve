<?php

namespace App\Actions\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Venta POS: valida stock, descuenta (explotando la receta en compuestos),
 * congela el COGS por línea y opcionalmente carga a una estancia activa.
 */
class CreateOrder
{
    public function __construct(protected RecordStockMovement $recordMovement) {}

    /**
     * @param  array{stay_id?: int|null, notes?: string|null, lines: array<int, array{product_id: int, qty: float}>}  $data
     *
     * @throws InsufficientStockException
     */
    public function handle(array $data, ?User $user = null): Order
    {
        return DB::transaction(function () use ($data, $user) {
            $stay = null;
            if (! empty($data['stay_id'])) {
                $stay = Stay::whereKey($data['stay_id'])->active()->first();
                if (! $stay) {
                    throw new InvalidArgumentException('La estancia ya no está activa; no se puede cargar a habitación.');
                }
            }

            // Lo que se carga a la habitación se cobra en el check-out, no
            // ahora; para el corte de caja no es efectivo en mano todavía.
            $method = $stay ? 'room' : ($data['payment_method'] ?? 'cash');

            $order = Order::create([
                'property_id' => $data['property_id'],
                'stay_id' => $stay?->id,
                'status' => Order::STATUS_COMPLETED,
                'payment_method' => $method,
                'total' => 0,
                'total_cost' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $total = 0.0;
            $totalCost = 0.0;

            foreach ($data['lines'] as $line) {
                $product = Product::with('recipeItems.ingredient')
                    ->whereKey($line['product_id'])
                    ->where('active', true)
                    ->firstOrFail();

                $qty = (float) $line['qty'];
                $unitCost = $product->currentUnitCost();

                if ($product->isComposite()) {
                    // Explota la receta: descuenta cada ingrediente.
                    foreach ($product->recipeItems as $item) {
                        $this->recordMovement->handle(
                            $item->ingredient,
                            'sale',
                            -($qty * (float) $item->quantity),
                            null,
                            $order,
                            "Venta {$product->name}",
                            $user,
                        );
                    }
                } elseif ($product->track_stock) {
                    $this->recordMovement->handle($product, 'sale', -$qty, null, $order, null, $user);
                }

                $lineTotal = round($qty * (float) $product->price, 2);

                $order->lines()->create([
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'unit_price' => $product->price,
                    'unit_cost' => $unitCost,
                    'total' => $lineTotal,
                ]);

                $total += $lineTotal;
                $totalCost += round($qty * $unitCost, 2);
            }

            $order->update(['total' => $total, 'total_cost' => $totalCost]);

            return $order;
        });
    }
}
