<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Quién repara: personal de casa o proveedor externo (plomero,
 * electricista). No entra al sistema — igual que las camaristas, existe
 * para poder registrar su trabajo y lo que cobró.
 */
class Technician extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'specialty',
        'external',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'external' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function kindLabel(): string
    {
        return $this->external ? 'Proveedor externo' : 'Personal de casa';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        // Primero los de casa: son a quienes se recurre por default.
        return $query->orderBy('external')->orderBy('name');
    }
}
