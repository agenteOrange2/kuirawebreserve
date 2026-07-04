<?php

namespace App\Actions\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Único punto que toca stock: ajusta el acumulado del insumo/producto bajo
 * lock y deja el movimiento auditado (spec §8). qty firmada: entrada +,
 * salida −. Llamar dentro de una transacción o dejará que él la abra.
 */
class RecordStockMovement
{
    /**
     * @param  Ingredient|Product  $stockable
     *
     * @throws InsufficientStockException
     */
    public function handle(
        Model $stockable,
        string $type,
        float $qty,
        ?float $unitCost = null,
        ?Model $ref = null,
        ?string $notes = null,
        ?User $user = null,
        bool $allowNegative = false,
    ): StockMovement {
        return DB::transaction(function () use ($stockable, $type, $qty, $unitCost, $ref, $notes, $user, $allowNegative) {
            // Re-lee bajo lock para serializar ventas/compras concurrentes.
            $locked = $stockable->newQuery()->whereKey($stockable->getKey())->lockForUpdate()->firstOrFail();

            $newQty = round((float) $locked->stock_qty + $qty, 3);

            if ($newQty < 0 && ! $allowNegative) {
                throw InsufficientStockException::for(
                    $locked->name,
                    (float) $locked->stock_qty,
                    $locked->unit ?? 'u',
                );
            }

            $locked->stock_qty = $newQty;

            // En compras, el último costo unitario actualiza el costo base.
            if ($type === 'purchase' && $unitCost !== null) {
                $locked->cost = $unitCost;
            }

            $locked->save();
            $stockable->setRawAttributes($locked->getAttributes(), true);

            return StockMovement::create([
                'stockable_type' => $locked->getMorphClass(),
                'stockable_id' => $locked->getKey(),
                'type' => $type,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'ref_type' => $ref?->getMorphClass(),
                'ref_id' => $ref?->getKey(),
                'notes' => $notes,
                'created_by' => $user?->id,
                'created_at' => now(),
            ]);
        });
    }
}
