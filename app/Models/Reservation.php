<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Reservation extends Model
{
    /** @use HasFactory<\Database\Factories\ReservationFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'property_id',
        'room_type_id',
        'room_id',
        'rate_plan_id',
        'reservation_group_id',
        'guest_id',
        'code',
        'guest_name',
        'num_people',
        'adults',
        'children',
        'vehicle_plate',
        'vehicle_desc',
        'eta',
        'starts_at',
        'ends_at',
        'status',
        'hold_expires_at',
        'source_channel',
        'total_amount',
        'extra_charges',
        'products',
        'extras',
        'experiences',
        'deposit_amount',
        'payment_status',
        'payment_due_at',
        'notes',
        'guest_notes',
        'cancellation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'adults' => 'integer',
            'children' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'extra_charges' => 'array',
            'products' => 'array',
            'extras' => 'array',
            'experiences' => 'array',
            'deposit_amount' => 'decimal:2',
            'payment_status' => \App\Enums\PaymentStatus::class,
            'payment_due_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('reservation')
            ->logOnly(['status', 'room_id', 'starts_at', 'ends_at', 'total_amount', 'cancellation_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReservationGroup::class, 'reservation_group_id');
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function stay(): HasOne
    {
        return $this->hasOne(Stay::class);
    }

    /** Tours comprados como extra de esta reserva (líneas en `experiences`). */
    public function experienceBookings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExperienceBooking::class);
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundedTotal(): float
    {
        return round((float) $this->refunds()->where('status', Refund::STATUS_COMPLETED)->sum('amount'), 2);
    }

    /**
     * Reembolso sugerido por la política de cancelación de la tarifa
     * (spec-pagos F4): lo pagado no reembolsado, menos la penalidad si se
     * cancela fuera de la ventana. null = la tarifa no define política
     * (decisión humana, como siempre). Es SUGERENCIA: el staff decide.
     */
    public function suggestedRefund(?\DateTimeInterface $at = null): ?float
    {
        $plan = $this->ratePlan;

        if (! $plan || ! $plan->hasCancellationPolicy()) {
            return null;
        }

        $refundable = max(0, round($this->paidTotal() - $this->refundedTotal(), 2));

        if ($refundable <= 0) {
            return null;
        }

        $deadline = $plan->cancelFreeDeadlineFor($this->starts_at);
        $moment = $at ? \Carbon\Carbon::instance(\Carbon\Carbon::parse($at)) : now();

        if ($deadline !== null && $moment->lte($deadline)) {
            return $refundable; // dentro de la ventana: se devuelve todo
        }

        $penalty = $plan->cancel_penalty_percent !== null ? (float) $plan->cancel_penalty_percent : 100.0;

        return max(0, round($refundable * (1 - $penalty / 100), 2));
    }

    public function paidTotal(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function pendingBalance(): float
    {
        return max(0, round((float) $this->total_amount - $this->paidTotal(), 2));
    }

    /**
     * Estado de pago derivado de la suma de abonos (spec §2.6.3): no se
     * marca a mano. Llamar tras registrar pagos o recalcular el total.
     */
    public function syncPaymentStatus(): void
    {
        $paid = $this->paidTotal();

        $this->payment_status = match (true) {
            $paid >= (float) $this->total_amount && (float) $this->total_amount > 0 => \App\Enums\PaymentStatus::Paid,
            (float) $this->deposit_amount > 0 && $paid >= (float) $this->deposit_amount => \App\Enums\PaymentStatus::DepositPaid,
            default => \App\Enums\PaymentStatus::Unpaid,
        };

        $this->save();
    }

    public function isPaymentOverdue(): bool
    {
        return $this->payment_due_at !== null
            && $this->payment_due_at->isPast()
            && $this->payment_status !== \App\Enums\PaymentStatus::Paid
            && in_array($this->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function formatCode(int $id, ?\DateTimeInterface $date = null): string
    {
        $year = (int) ($date?->format('Y') ?? now()->format('Y'));

        return sprintf('RES-%d-%04d', $year, $id);
    }

    public function displayCode(): string
    {
        return $this->code ?: self::formatCode($this->id, $this->created_at);
    }

    /**
     * Reservas que bloquean disponibilidad: confirmadas / en casa, y
     * pendientes cuyo hold sigue vigente (spec §7).
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
                ->orWhere(function (Builder $q) {
                    $q->where('status', ReservationStatus::Pending)
                        ->where('hold_expires_at', '>', now());
                });
        });
    }

    /**
     * Solape de rangos: (start_a < end_b) AND (end_a > start_b).
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }
}
