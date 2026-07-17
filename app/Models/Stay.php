<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Ocupación real de una habitación (check-in hecho). Puede venir de una
 * reserva o ser walk-in directo.
 */
class Stay extends Model
{
    use LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'room_id',
        'reservation_id',
        'rate_plan_id',
        'guest_id',
        'guest_name',
        'num_people',
        'vehicle_plate',
        'vehicle_desc',
        'check_in_at',
        'planned_end_at',
        'check_out_at',
        'status',
        'amount',
        'extra_charges',
        'channel',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'check_out_at' => 'datetime',
            'amount' => 'decimal:2',
            'extra_charges' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('stay')
            ->logOnly(['status', 'room_id', 'check_in_at', 'check_out_at', 'amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Cuenta final de la estancia (folio): hospedaje pendiente + consumos
     * POS cargados a la habitación aún no liquidados.
     *
     * @return array<string, mixed>
     */
    public function folio(): array
    {
        // Hospedaje: con reserva manda su control de pagos; walk-in sin
        // reserva usa el monto de la estancia menos lo ya liquidado en folio.
        if ($this->reservation) {
            $lodgingTotal = (float) $this->reservation->total_amount;
            $lodgingPaid = $this->reservation->paidTotal();
        } else {
            $lodgingTotal = (float) $this->amount;
            $lodgingPaid = round((float) $this->payments()->where('kind', Payment::KIND_LODGING)->sum('amount'), 2);
        }
        $lodgingPending = max(0, round($lodgingTotal - $lodgingPaid, 2));

        $unsettledOrders = $this->orders()
            ->with('lines.product:id,name')
            ->where('status', Order::STATUS_COMPLETED)
            ->where('payment_method', 'room')
            ->whereNull('settled_at')
            ->get();

        $consumptionPending = round((float) $unsettledOrders->sum('total'), 2);

        return [
            'lodging_total' => $lodgingTotal,
            'lodging_paid' => $lodgingPaid,
            'lodging_pending' => $lodgingPending,
            'orders' => $unsettledOrders,
            'consumption_pending' => $consumptionPending,
            'grand_pending' => round($lodgingPending + $consumptionPending, 2),
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Solape con estancias activas (para disponibilidad).
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('check_in_at', '<', $end)->where('planned_end_at', '>', $start);
    }
}
