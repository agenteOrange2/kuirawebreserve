<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Camarista: personal que limpia habitaciones SIN acceso al sistema.
 *
 * Vive aparte de `users` a propósito — no necesita credenciales, no consume
 * el límite de usuarios del plan y su baja no debe borrar su historial.
 * `user_id` solo se llena si esa persona además entra al panel (una
 * supervisora, por ejemplo).
 */
class Housekeeper extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'active',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function cleanings(): HasMany
    {
        return $this->hasMany(RoomCleaning::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }
}
