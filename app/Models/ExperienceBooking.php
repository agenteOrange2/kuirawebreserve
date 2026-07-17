<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserva de una experiencia (folio propio EXP-YYYY-NNNN). Independiente
 * de las reservas de habitación: puede ligarse a una estancia
 * (reservation_id) pero no la necesita — un turista de paso puede
 * comprar solo el tour.
 */
class ExperienceBooking extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_CONFIRMED => 'Confirmada',
        self::STATUS_CANCELLED => 'Cancelada',
        self::STATUS_COMPLETED => 'Completada',
    ];

    protected $fillable = [
        'experience_session_id',
        'guest_id',
        'reservation_id',
        'reservation_group_id',
        'guest_name',
        'people',
        'total',
        'status',
        'code',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'people' => 'integer',
            'total' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExperienceSession::class, 'experience_session_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** Grupo (GRP-) al que se sumó como extra, si aplica. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ReservationGroup::class, 'reservation_group_id');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function isPaid(): bool
    {
        return $this->paymentRequests()->where('status', PaymentRequest::STATUS_PAID)->exists();
    }

    public static function formatCode(int $id, ?\DateTimeInterface $date = null): string
    {
        $year = (int) ($date?->format('Y') ?? now()->format('Y'));

        return sprintf('EXP-%d-%04d', $year, $id);
    }

    public function displayCode(): string
    {
        return $this->code ?: self::formatCode($this->id, $this->created_at);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
