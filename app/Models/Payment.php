<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abono registrado a una reserva o a una estancia (folio): el libro de
 * dinero CONFIRMADO, append-only. Los intentos de cobro (pendientes,
 * vencidos, rechazados) viven en payment_requests, no aquí.
 *
 * `kind`: null = abono normal de reserva · 'lodging' = hospedaje liquidado
 * en el folio (walk-in) · 'consumption' = consumos POS del folio.
 */
class Payment extends Model
{
    public const UPDATED_AT = null;

    /** Métodos de mostrador (los que el staff captura a mano). */
    public const METHODS = ['cash', 'card', 'transfer'];

    /**
     * Pago cobrado por pasarela (spec-pagos §4.2): nunca se captura a mano
     * (lo crea el webhook) y se excluye del corte de caja (received_by null).
     */
    public const METHOD_ONLINE = 'online';

    public const KIND_LODGING = 'lodging';

    public const KIND_CONSUMPTION = 'consumption';

    protected $fillable = [
        'reservation_id',
        'experience_booking_id',
        'stay_id',
        'payment_request_id',
        'amount',
        'fee_amount',
        'method',
        'gateway',
        'gateway_ref',
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
            'fee_amount' => 'decimal:2',
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

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundedTotal(): float
    {
        return round((float) $this->refunds()->where('status', Refund::STATUS_COMPLETED)->sum('amount'), 2);
    }

    /** Lo que aún puede devolverse de este pago. */
    public function refundableAmount(): float
    {
        return max(0, round((float) $this->amount - $this->refundedTotal(), 2));
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
