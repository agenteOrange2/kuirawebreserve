<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Builder;

/**
 * Aviso del panel de plataforma. Ver PlatformAlertScanner.
 *
 * Estados (se excluyen en este orden): resuelto (la condición ya no se da),
 * descartado (un admin dijo "ya lo sé"), pospuesto (vuelve sola en
 * snoozed_until) y abierto. Leído es aparte: un abierto puede estar leído.
 */
class PlatformAlert extends CentralModel
{
    public const SEVERITIES = ['danger', 'warning', 'info'];

    protected $fillable = [
        'key',
        'type',
        'severity',
        'tenant_id',
        'title',
        'body',
        'url',
        'data',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'read_at',
        'dismissed_at',
        'dismissed_by',
        'snoozed_until',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    /** Lo que hoy pide atención: ni resuelto, ni descartado, ni pospuesto. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    public function scopeSnoozed(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->where('snoozed_until', '>', now());
    }

    public function scopeDismissed(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->whereNotNull('dismissed_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    public function dismisser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'dismissed_by');
    }

    public static function rank(string $severity): int
    {
        return array_search($severity, self::SEVERITIES, true) === false
            ? count(self::SEVERITIES)
            : (int) array_search($severity, self::SEVERITIES, true);
    }
}
