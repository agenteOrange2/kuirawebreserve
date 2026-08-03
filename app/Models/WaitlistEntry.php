<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada de la lista de espera (módulo lista-espera): alguien quiso
 * reservar sin disponibilidad y dejó su contacto. Cuando una cancelación
 * libera fechas solapadas, WaitlistNotifier le avisa y la sella como
 * notified — un aviso por entrada, nunca spam.
 */
class WaitlistEntry extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'room_type_id',
        'starts_at',
        'ends_at',
        'guest_name',
        'guest_phone',
        'guest_email',
        'status',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'notified_at' => 'datetime',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    /**
     * Entradas cuyo rango de fechas se solapa con el rango dado (fechas
     * calendario: [starts_at, ends_at) contra [start, end)).
     */
    public function scopeOverlappingDates(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query
            ->whereDate('starts_at', '<', $end->format('Y-m-d'))
            ->whereDate('ends_at', '>', $start->format('Y-m-d'));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING => 'En espera',
            self::STATUS_NOTIFIED => 'Avisado',
            self::STATUS_CONVERTED => 'Convertida',
            self::STATUS_EXPIRED => 'Expirada',
            default => $this->status,
        };
    }
}
