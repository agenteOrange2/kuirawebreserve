<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockMovement;
use Inertia\Inertia;
use Inertia\Response;

class InventoryPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();

        $ingredients = Ingredient::orderBy('name')->get();
        $products = Product::with('recipeItems.ingredient:id,name,unit')->orderBy('name')->get();

        // Valor del inventario: solo cuenta lo que lleva stock (productos
        // simples con track_stock + insumos).
        $stockProducts = $products->filter(fn (Product $p) => $p->type === Product::TYPE_SIMPLE && $p->track_stock);
        $productsCost = $stockProducts->sum(fn (Product $p) => (float) $p->stock_qty * $p->currentUnitCost());
        $ingredientsCost = $ingredients->sum(fn (Ingredient $i) => (float) $i->stock_qty * (float) $i->cost);

        // Valor invertido en inventario (productos vendibles + insumos).
        $valueAtCost = round($productsCost + $ingredientsCost, 2);
        // Valor de venta del stock de productos (los insumos no se venden directo).
        $valueAtPrice = round($stockProducts->sum(fn (Product $p) => (float) $p->stock_qty * (float) $p->price), 2);
        // Margen sobre lo vendible: precio − costo de los mismos productos.
        $potentialMargin = round($valueAtPrice - $productsCost, 2);
        $lowStockCount = $products->filter(fn (Product $p) => $p->isLowStock())->count()
            + $ingredients->filter(fn (Ingredient $i) => $i->isLowStock())->count();

        $movementLabels = ['purchase' => 'Compra', 'sale' => 'Venta', 'waste' => 'Merma', 'adjustment' => 'Ajuste'];

        $movements = StockMovement::query()
            ->with(['stockable:id,name,unit', 'createdBy:id,name'])
            ->latest('created_at')
            ->take(25)
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'item' => $m->stockable?->name ?? '—',
                'unit' => $m->stockable?->unit,
                'type' => $m->type,
                'type_label' => $movementLabels[$m->type] ?? $m->type,
                'qty' => (float) $m->qty,
                'unit_cost' => $m->unit_cost !== null ? (float) $m->unit_cost : null,
                'notes' => $m->notes,
                'by' => $m->createdBy?->name ?? 'Sistema',
                'at' => $m->created_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('tenant/inventory/Index', [
            'property' => $property->only(['id', 'name']),
            'summary' => [
                'products_total' => $products->count(),
                'products_active' => $products->where('active', true)->count(),
                'ingredients_total' => $ingredients->count(),
                'low_stock' => $lowStockCount,
                'value_cost' => $valueAtCost,
                'value_price' => $valueAtPrice,
                'potential_margin' => $potentialMargin,
            ],
            'categories' => $products->pluck('category')->filter()->unique()->sort()->values(),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category,
                'sku' => $p->sku,
                'type' => $p->type,
                'unit' => $p->unit,
                'price' => $p->price,
                'cost' => $p->currentUnitCost(),
                'margin' => round((float) $p->price - $p->currentUnitCost(), 2),
                'track_stock' => $p->track_stock,
                'stock_qty' => (float) $p->stock_qty,
                'reorder_point' => $p->reorder_point !== null ? (float) $p->reorder_point : null,
                'active' => $p->active,
                'low_stock' => $p->isLowStock(),
                'recipe' => $p->recipeItems->map(fn ($item) => [
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient' => $item->ingredient?->name,
                    'unit' => $item->ingredient?->unit,
                    'quantity' => (float) $item->quantity,
                ]),
            ]),
            'ingredients' => $ingredients->map(fn (Ingredient $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'unit' => $i->unit,
                'stock_qty' => (float) $i->stock_qty,
                'reorder_point' => $i->reorder_point !== null ? (float) $i->reorder_point : null,
                'cost' => $i->cost,
                'value' => round((float) $i->stock_qty * (float) $i->cost, 2),
                'low_stock' => $i->isLowStock(),
            ]),
            'movements' => $movements,
        ]);
    }
}
