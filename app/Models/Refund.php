<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Reembolso de un pago (spec-pagos §6.6): fila propia, el Payment original
 * no se toca (libro append-only). gateway null = reembolso manual (efectivo
 * en mostrador o hecho por el hotel en el dashboard del proveedor).
 */
class Refund extends Model
{
    use LogsActivity;
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'payment_id',
        'reservation_id',
        'amount',
        'status',
        'gateway',
        'gateway_ref',
        'reason',
        'created_by',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    /** Bitácora: el dinero que sale también deja rastro de quién y cuándo. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment')
            ->logOnly(['amount', 'status', 'payment_id', 'reservation_id', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
