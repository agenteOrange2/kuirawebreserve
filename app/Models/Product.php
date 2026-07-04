<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    public const TYPE_SIMPLE = 'simple';

    public const TYPE_COMPOSITE = 'composite';

    protected $fillable = [
        'property_id',
        'sku',
        'name',
        'category',
        'type',
        'unit',
        'price',
        'cost',
        'track_stock',
        'stock_qty',
        'reorder_point',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'track_stock' => 'boolean',
            'stock_qty' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'active' => 'boolean',
        ];
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function isComposite(): bool
    {
        return $this->type === self::TYPE_COMPOSITE;
    }

    public function isLowStock(): bool
    {
        return $this->type === self::TYPE_SIMPLE
            && $this->track_stock
            && $this->reorder_point !== null
            && (float) $this->stock_qty <= (float) $this->reorder_point;
    }

    /**
     * Costo unitario actual: propio (simple) o el de su receta (composite).
     */
    public function currentUnitCost(): float
    {
        if (! $this->isComposite()) {
            return (float) $this->cost;
        }

        return round($this->recipeItems->sum(
            fn (Recipe $item) => (float) $item->quantity * (float) $item->ingredient->cost,
        ), 2);
    }
}
