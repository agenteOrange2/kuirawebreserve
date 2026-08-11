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
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('incident')
            ->logOnly(['title', 'room_id', 'category', 'source', 'priority', 'status', 'assigned_to', 'resolution_notes'])
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
