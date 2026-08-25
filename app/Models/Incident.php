<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Incidencia de mantenimiento: ticket con habitación (o área general),
 * prioridad, responsable y resolución. Las fotos van al disco privado —
 * evidencia interna del hotel, nunca pública.
 */
class Incident extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED];

    public const PRIORITIES = ['low', 'medium', 'high'];

    /** Catálogo de tipos de falla (documento base): agrupa los reportes. */
    public const CATEGORIES = [
        'clima' => 'Clima / aire acondicionado',
        'electricidad' => 'Falla eléctrica',
        'plomeria' => 'Plomería / fuga de agua',
        'tv' => 'TV / entretenimiento',
        'jacuzzi' => 'Jacuzzi / alberca',
        'mobiliario' => 'Mobiliario',
        'limpieza' => 'Limpieza',
        'seguridad' => 'Seguridad',
        'otro' => 'Otro',
    ];

    public const SOURCE_STAFF = 'staff';

    public const SOURCE_GUEST = 'guest';

    protected $fillable = [
        'room_id',
        'title',
        'category',
        'source',
        'description',
        'priority',
        'status',
        'reported_by',
        'assigned_to',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
        'due_at',
        'overdue_notified_at',
        'cost',
        'technician_id',
        'stay_id',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'due_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'cost' => 'decimal:2',
        ];
    }

    /**
     * El plazo se calcula solo: al crear, y al cambiar la prioridad de una
     * incidencia que sigue abierta (subir a alta debe adelantar el
     * vencimiento, no arrastrar el plazo viejo).
     */
    protected static function booted(): void
    {
        static::creating(function (self $incident) {
            $incident->due_at ??= app(\App\Services\IncidentPolicy::class)
                ->dueAt($incident->priority ?? 'medium');
        });

        static::updating(function (self $incident) {
            if ($incident->isDirty('priority') && $incident->status !== self::STATUS_RESOLVED) {
                $incident->due_at = app(\App\Services\IncidentPolicy::class)->dueAt(
                    $incident->priority,
                    $incident->created_at,
                );
                // Cambió el plazo: si vuelve a vencerse, vuelve a avisar.
                $incident->overdue_notified_at = null;
            }
        });
    }

    /** ¿Se pasó de su tiempo objetivo y sigue sin resolverse? */
    /**
     * Lo que se le cobró al huésped por este daño: el cargo de la estancia
     * ligada cuyo concepto coincide con el del ticket. Sin estancia no hay
     * a quién cobrarle, y devuelve null — que no es lo mismo que cero.
     */
    public function chargedToGuest(): ?float
    {
        if ($this->stay_id === null) {
            return null;
        }

        $concept = mb_strtolower(trim(str_starts_with($this->title, 'Daño: ')
            ? mb_substr($this->title, 6)
            : $this->title));

        $match = collect($this->stay?->extra_charges ?? [])
            ->first(fn (array $line) => ($line['kind'] ?? 'damage') === 'damage'
                && mb_strtolower(trim((string) ($line['concept'] ?? ''))) === $concept);

        return $match ? round((float) ($match['amount'] ?? 0), 2) : null;
    }

    public function isOverdue(): bool
    {
        return app(\App\Services\IncidentPolicy::class)->isOverdue($this);
    }

    /** Horas que lleva abierta (o las que tardó en resolverse). */
    public function ageHours(): int
    {
        return (int) $this->created_at->diffInHours($this->resolved_at ?? now());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('incident')
            ->logOnly(['title', 'room_id', 'category', 'source', 'priority', 'status', 'assigned_to', 'resolution_notes', 'cost', 'technician_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->useDisk('local');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** Quién la reparó (personal de casa o proveedor externo). */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /** La estancia que causó el daño, cuando lo reportó el check-out. */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Abiertas o en proceso: lo que todavía requiere atención. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS]);
    }

    /**
     * Buscador del listado: el texto del ticket y el número de la
     * habitación, que es como la gente busca ("la 101", "regadera").
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhere('resolution_notes', 'like', $like)
            ->orWhereHas('room', fn (Builder $r) => $r
                ->where('number', 'like', $like)
                ->orWhere('name', 'like', $like)));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Abierta',
            self::STATUS_IN_PROGRESS => 'En proceso',
            self::STATUS_RESOLVED => 'Resuelta',
            default => $this->status,
        };
    }

    public function priorityLabel(): string
    {
        return match ($this->priority) {
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
            default => $this->priority,
        };
    }

    public function categoryLabel(): ?string
    {
        return $this->category !== null
            ? (self::CATEGORIES[$this->category] ?? $this->category)
            : null;
    }

    public function isGuestReported(): bool
    {
        return $this->source === self::SOURCE_GUEST;
    }
}
