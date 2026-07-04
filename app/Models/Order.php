<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta POS (bar/cocina/minibar). Puede cargarse a una estancia activa.
 */
class Order extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VOID = 'void';

    public const METHODS = ['cash', 'card', 'transfer'];

    protected $fillable = [
        'property_id',
        'stay_id',
        'status',
        'payment_method',
        'settled_at',
        'settled_by',
        'total',
        'total_cost',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
