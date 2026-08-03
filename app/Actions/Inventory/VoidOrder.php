<?php

namespace App\Actions\Inventory;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancela una venta POS: devuelve al inventario lo que descontó (explotando
 * la receta en los compuestos) y la marca como anulada.
 *
 * El corte de caja y el folio de la habitación solo suman ventas en estado
 * `completed`, así que anularla la saca de ambos sin tocar sus cálculos.
 */
class VoidOrder
{
    public function __construct(protected RecordStockMovement $recordMovement) {}

    public function handle(Order $order, ?User $user = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $user, $reason) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->isVoid()) {
                throw new InvalidArgumentException('Esta venta ya estaba cancelada.');
            }

            if ($order->isSettled()) {
                throw new InvalidArgumentException(
                    'Esta venta ya se liquidó en el check-out; para devolver el dinero se registra un reembolso.',
                );
            }

            $lines = $order->lines()->with('product.recipeItems.ingredient')->get();

            foreach ($lines as $line) {
                $product = $line->product;

                if ($product === null) {
                    continue;
                }

                $qty = (float) $line->qty;

                if ($product->isComposite()) {
                    foreach ($product->recipeItems as $item) {
                        $this->recordMovement->handle(
                            $item->ingredient,
                            'void',
                            $qty * (float) $item->quantity,
                            null,
                            $order,
                            "Cancelación de venta · {$product->name}",
                            $user,
                        );
                    }
                } elseif ($product->track_stock) {
                    $this->recordMovement->handle(
                        $product,
                        'void',
                        $qty,
                        null,
                        $order,
                        'Cancelación de venta',
                        $user,
                    );
                }
            }

            $order->update([
                'status' => Order::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user?->id,
                'void_reason' => $reason,
            ]);

            return $order;
        });
    }
}
