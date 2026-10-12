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

    /** La cerró la camarista (o quien capturó) con el formulario. */
    public const CLOSED_BY_STAFF = 'staff';

    /** Se cumplió el tiempo asignado y el reloj liberó la habitación. */
    public const CLOSED_BY_CLOCK = 'clock';

    /**
     * El semáforo la sacó de "en limpieza" por otro camino (mantenimiento,
     * otra persona desde el plano) sin que nadie la terminara.
     */
    public const CLOSED_BY_STATUS = 'status';

    /** Cómo se lee cada cierre en la bitácora. */
    public const OUTCOME_ON_TIME = 'a_tiempo';

    public const OUTCOME_LATE = 'tarde';

    public const OUTCOME_CLOCK = 'reloj';

    public const OUTCOME_INTERRUPTED = 'interrumpida';

    public const OUTCOMES = [
        self::OUTCOME_ON_TIME => 'A tiempo',
        self::OUTCOME_LATE => 'Fuera de tiempo',
        self::OUTCOME_CLOCK => 'La cerró el reloj',
        self::OUTCOME_INTERRUPTED => 'Interrumpida',
    ];

    protected $fillable = [
        'room_id',
        'housekeeper_id',
        'stay_id',
        'kind',
        'started_at',
        'ended_at',
        'minutes',
        'closed_by',
        'target_minutes',
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
            'target_minutes' => 'integer',
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
     * `closedBy` dice quién la dio por terminada: el reporte no mide igual
     * el trabajo de una camarista que el tiempo que esperó el reloj.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function close(array $attributes = [], ?\DateTimeInterface $endedAt = null, string $closedBy = self::CLOSED_BY_STAFF): self
    {
        $ended = $endedAt ? \Illuminate\Support\Carbon::parse($endedAt) : now();

        $this->update([
            ...$attributes,
            'ended_at' => $ended,
            // Nunca negativo: una hora de salida anterior a la de entrada
            // (dedazo al capturar) se guarda como 0 en vez de ensuciar los
            // promedios del reporte.
            'minutes' => max(0, (int) $this->started_at->diffInMinutes($ended)),
            'closed_by' => $closedBy,
        ]);

        return $this;
    }

    /**
     * Cómo terminó: a tiempo o fuera de tiempo (la cerró una persona), la
     * cerró el reloj, o la interrumpió otro cambio del semáforo. Null si
     * sigue abierta o si es de antes de que existiera el tiempo asignado.
     */
    public function outcome(): ?string
    {
        return match (true) {
            $this->isOpen() => null,
            $this->closed_by === self::CLOSED_BY_CLOCK => self::OUTCOME_CLOCK,
            $this->closed_by === self::CLOSED_BY_STATUS => self::OUTCOME_INTERRUPTED,
            $this->target_minutes === null => null,
            $this->minutes <= $this->target_minutes => self::OUTCOME_ON_TIME,
            default => self::OUTCOME_LATE,
        };
    }

    /** ¿Ya se pasó del tiempo asignado mientras sigue abierta? */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->target_minutes !== null
            && $this->elapsedMinutes() > $this->target_minutes;
    }

    /** Hora a la que vence el tiempo asignado. */
    public function dueAt(): ?\Carbon\CarbonInterface
    {
        return $this->target_minutes !== null && $this->started_at
            ? $this->started_at->addMinutes($this->target_minutes)
            : null;
    }

    /**
     * Filtra por resultado del cierre; mismo criterio que outcome() pero en
     * SQL, para que la bitácora pagine sobre el filtro y no sobre la página.
     */
    public function scopeOutcome(Builder $query, string $outcome): Builder
    {
        return match ($outcome) {
            self::OUTCOME_CLOCK => $query->where('closed_by', self::CLOSED_BY_CLOCK),
            self::OUTCOME_INTERRUPTED => $query->where('closed_by', self::CLOSED_BY_STATUS),
            self::OUTCOME_ON_TIME => $query->where('closed_by', self::CLOSED_BY_STAFF)
                ->whereNotNull('target_minutes')
                ->whereColumn('minutes', '<=', 'target_minutes'),
            self::OUTCOME_LATE => $query->where('closed_by', self::CLOSED_BY_STAFF)
                ->whereNotNull('target_minutes')
                ->whereColumn('minutes', '>', 'target_minutes'),
            default => $query,
        };
    }

    /**
     * Cierra lo que quedó abierto en una habitación que salió de "en
     * limpieza" sin pasar por el formulario. Sin esto el cronómetro corría
     * para siempre y la siguiente limpieza del cuarto se rechazaba.
     */
    public static function closeOpenFor(Room $room, string $closedBy): int
    {
        $open = static::query()->where('room_id', $room->id)->open()->get();

        foreach ($open as $cleaning) {
            $cleaning->close([], null, $closedBy);
        }

        return $open->count();
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
