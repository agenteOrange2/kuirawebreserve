<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una habitación limpiada: quién, cuándo entró y salió, qué hizo y con qué
 * ropa. Es el registro que le faltaba al semáforo.
 *
 * Convive con `room_status_logs` sin reemplazarlo: aquel sigue contando la
 * verdad del semáforo (incluidos los cambios automáticos por reloj), este
 * cuenta el trabajo de una persona. Cuando el reloj mueve el estado sin que
 * nadie registre, simplemente NO hay fila aquí — y el panel lo muestra como
 * "sin registrar" en vez de inventar una camarista.
 */
class RoomCleaning extends Model
{
    /** Tras un check-out: la habitación se hace a fondo. */
    public const KIND_CHECKOUT = 'salida';

    /** Con el huésped adentro: se rehace la cama y se reponen amenidades. */
    public const KIND_TOUCHUP = 'retoque';

    public const KIND_DEEP = 'profunda';

    public const KINDS = [
        self::KIND_CHECKOUT => 'De salida',
        self::KIND_TOUCHUP => 'Retoque',
        self::KIND_DEEP => 'Profunda',
    ];

    /** Registrada desde el plano, con cronómetro. */
    public const SOURCE_FLOORPLAN = 'plano';

    /** Capturada después, con las horas escritas a mano. */
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'room_id',
        'housekeeper_id',
        'stay_id',
        'kind',
        'started_at',
        'ended_at',
        'minutes',
        'checklist',
        'linens',
        'notes',
        'incident_id',
        'recorded_by',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'checklist' => 'array',
            'linens' => 'array',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function housekeeper(): BelongsTo
    {
        return $this->belongsTo(Housekeeper::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Minutos trabajados: los sellados al cerrar, o los que van corriendo si
     * la limpieza sigue abierta.
     */
    public function elapsedMinutes(): int
    {
        if ($this->minutes !== null) {
            return $this->minutes;
        }

        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now());
    }

    /**
     * Cierra la limpieza sellando la duración. Se pasa `endedAt` cuando la
     * hora la escribió una persona (captura manual); si no, es ahora.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function close(array $attributes = [], ?\DateTimeInterface $endedAt = null): self
    {
        $ended = $endedAt ? \Illuminate\Support\Carbon::parse($endedAt) : now();

        $this->update([
            ...$attributes,
            'ended_at' => $ended,
            // Nunca negativo: una hora de salida anterior a la de entrada
            // (dedazo al capturar) se guarda como 0 en vez de ensuciar los
            // promedios del reporte.
            'minutes' => max(0, (int) $this->started_at->diffInMinutes($ended)),
        ]);

        return $this;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }

    /** Limpiezas iniciadas dentro del rango (el reporte mide por inicio). */
    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('started_at', [$from, $to]);
    }
}
