<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Turno de trabajo de un encargado: registra quién estuvo a cargo y en qué
 * horario, con el fondo de caja inicial. Un turno abierto (ended_at null)
 * es el que está corriendo ahora; al cerrarlo se hace su corte de venta.
 */
class Shift extends Model
{
    protected $fillable = [
        'property_id',
        'user_id',
        'started_at',
        'ended_at',
        'opening_cash',
        'notes',
        'created_by',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'opening_cash' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
