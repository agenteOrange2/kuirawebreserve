<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    // `void` es la devolución al inventario cuando se cancela una venta: se
    // distingue de `adjustment` para que el historial diga por qué volvió.
    public const TYPES = ['purchase', 'sale', 'waste', 'adjustment', 'void'];

    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'type',
        'qty',
        'unit_cost',
        'ref_type',
        'ref_id',
        'notes',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function ref(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
