<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corte de caja por encargado y ÁMBITO: 'rooms' contabiliza los cobros de
 * recepción (reservas/estancias + fianzas), 'pos' lo vendido en punto de
 * venta. Cada uno con su desglose por método y su arqueo de efectivo.
 * 'all' es el formato histórico combinado (solo cortes viejos).
 */
class CashCut extends Model
{
    public const SCOPE_ROOMS = 'rooms';

    public const SCOPE_POS = 'pos';

    public const SCOPE_ALL = 'all';

    protected $fillable = [
        'property_id',
        'user_id',
        'shift_id',
        'scope',
        'opened_at',
        'closed_at',
        'orders_count',
        'orders_total',
        'orders_cost',
        'payments_count',
        'payments_total',
        'cash_total',
        'card_total',
        'transfer_total',
        'grand_total',
        'expected_cash',
        'opening_cash',
        'counted_cash',
        'difference',
        'pending_count',
        'pending_total',
        'pending_items',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'orders_total' => 'decimal:2',
            'orders_cost' => 'decimal:2',
            'payments_total' => 'decimal:2',
            'cash_total' => 'decimal:2',
            'card_total' => 'decimal:2',
            'transfer_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'opening_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'pending_total' => 'decimal:2',
            'pending_items' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            self::SCOPE_ROOMS => 'Recepción',
            self::SCOPE_POS => 'Punto de venta',
            default => 'Combinado',
        };
    }
}
