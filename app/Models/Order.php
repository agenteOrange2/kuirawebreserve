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
        'shift_id',
        'status',
        'payment_method',
        'payment_reference',
        'settled_at',
        'settled_by',
        'subtotal',
        'discount',
        'discount_reason',
        'tip',
        'total',
        'total_cost',
        'notes',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tip' => 'decimal:2',
            'total' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'settled_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /** Una venta ya liquidada en el check-out no se cancela: se reembolsa. */
    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
