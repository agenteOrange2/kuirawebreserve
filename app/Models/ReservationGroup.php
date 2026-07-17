<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de reservas (módulo `grupos`): folio GRP- que agrupa varias
 * reservas de habitación hechas de un jalón. Las reservas siguen siendo
 * las de siempre; el grupo aporta la vista y las acciones de conjunto.
 */
class ReservationGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'code',
        'guest_id',
        'guest_name',
        'notes',
        'created_by',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /** Tours comprados como extra del grupo (cuelgan del GRP-, no de un cuarto). */
    public function experienceBookings(): HasMany
    {
        return $this->hasMany(ExperienceBooking::class);
    }

    /** Reservas de experiencia vivas del grupo (pendientes + confirmadas). */
    public function liveExperienceBookings(): HasMany
    {
        return $this->experienceBookings()
            ->whereIn('status', [ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED]);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public static function formatCode(int $id, ?\DateTimeInterface $date = null): string
    {
        $year = (int) ($date?->format('Y') ?? now()->format('Y'));

        return sprintf('GRP-%d-%04d', $year, $id);
    }

    public function displayCode(): string
    {
        return $this->code ?: self::formatCode($this->id, $this->created_at);
    }

    /** Total del grupo: habitaciones + experiencias vivas colgadas del grupo. */
    public function totalAmount(): float
    {
        return round(
            (float) $this->reservations()->sum('total_amount')
                + (float) $this->liveExperienceBookings()->sum('total'),
            2,
        );
    }
}
