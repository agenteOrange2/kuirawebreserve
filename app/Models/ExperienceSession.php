<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesión de una experiencia: una fecha/hora concreta con cupo TOTAL. El
 * cupo es duro (se hace cumplir bajo lock en CreateExperienceBooking) —
 * misma filosofía anti-doble-venta que las habitaciones.
 */
class ExperienceSession extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'experience_id',
        'experience_slot_id',
        'starts_at',
        'capacity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    /** Horario que la generó; null = sesión creada a mano. */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(ExperienceSlot::class, 'experience_slot_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ExperienceBooking::class);
    }

    /** Personas ya apartadas (reservas vivas: pendientes + confirmadas). */
    public function peopleBooked(): int
    {
        return (int) $this->bookings()
            ->whereIn('status', [ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED])
            ->sum('people');
    }

    public function remainingSpots(): int
    {
        return max(0, $this->capacity - $this->peopleBooked());
    }

    public function isBookable(): bool
    {
        return $this->status === self::STATUS_SCHEDULED && $this->starts_at->isFuture();
    }
}
