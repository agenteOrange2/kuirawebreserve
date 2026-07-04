<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corte de caja/ventas por encargado: contabiliza lo que un usuario cobró
 * en un periodo (ventas POS + abonos de reservas) con desglose por método
 * de pago y arqueo de efectivo (esperado vs. contado).
 */
class CashCut extends Model
{
    protected $fillable = [
        'property_id',
        'user_id',
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
        'counted_cash',
        'difference',
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
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
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
}
