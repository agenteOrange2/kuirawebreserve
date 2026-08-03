<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bloqueo de habitación por fechas (mantenimiento programado): los días
 * starts_at..ends_at (inclusive) quedan fuera de la venta. No mueve el
 * semáforo — solo descuenta disponibilidad futura vía AvailabilityService.
 */
class RoomBlock extends Model
{
    /** @use HasFactory<\Database\Factories\RoomBlockFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'starts_at',
        'ends_at',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Bloqueos que chocan con un rango de estancia [start, end). El bloqueo
     * cubre días completos; las NOCHES que ocupa la estancia van del día de
     * llegada al día anterior a la salida — así un check-out en la mañana
     * del primer día bloqueado sí se permite (el mantenimiento empieza
     * cuando el huésped se va), pero un check-in dentro del bloqueo no.
     * Se comparan puras fechas (string YYYY-MM-DD) para que MySQL y SQLite
     * respondan igual.
     */
    public function scopeOverlapping(Builder $query, DateTimeInterface $start, DateTimeInterface $end): Builder
    {
        [$firstNight, $lastNight] = self::occupiedNights($start, $end);

        return $query
            ->whereDate('starts_at', '<=', $lastNight)
            ->whereDate('ends_at', '>=', $firstNight);
    }

    /** Bloqueos vigentes o futuros (los pasados ya no afectan la venta). */
    public function scopeCurrentOrFuture(Builder $query): Builder
    {
        return $query->whereDate('ends_at', '>=', now()->toDateString());
    }

    /**
     * Primera y última NOCHE ocupada por un rango [start, end): la salida en
     * la mañana no ocupa la noche del día de salida. Estancias por bloque
     * dentro del mismo día ocupan solo ese día.
     *
     * @return array{string, string}
     */
    public static function occupiedNights(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $first = Carbon::instance($start)->toDateString();
        $last = Carbon::instance($end)->subDay()->toDateString();

        return [$first, max($first, $last)];
    }

    public function coversRange(DateTimeInterface $start, DateTimeInterface $end): bool
    {
        [$firstNight, $lastNight] = self::occupiedNights($start, $end);

        return $this->starts_at->toDateString() <= $lastNight
            && $this->ends_at->toDateString() >= $firstNight;
    }
}
