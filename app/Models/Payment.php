<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abono registrado a una reserva o a una estancia (folio). El cobro con
 * pasarela es fase 7; esto registra pagos hechos por fuera.
 *
 * `kind`: null = abono normal de reserva · 'lodging' = hospedaje liquidado
 * en el folio (walk-in) · 'consumption' = consumos POS del folio.
 */
class Payment extends Model
{
    public const UPDATED_AT = null;

    public const METHODS = ['cash', 'card', 'transfer'];

    public const KIND_LODGING = 'lodging';

    public const KIND_CONSUMPTION = 'consumption';

    protected $fillable = [
        'reservation_id',
        'stay_id',
        'amount',
        'method',
        'kind',
        'reference',
        'notes',
        'received_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            default => $method,
        };
    }
}
