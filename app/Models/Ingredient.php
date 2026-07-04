<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Ingredient extends Model
{
    /** @use HasFactory<\Database\Factories\IngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'name',
        'unit',
        'stock_qty',
        'reorder_point',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'stock_qty' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'cost' => 'decimal:2',
        ];
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point !== null && (float) $this->stock_qty <= (float) $this->reorder_point;
    }
}
