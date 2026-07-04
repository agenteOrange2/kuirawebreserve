<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de turno del hotel (matutino, vespertino, nocturno…): nombre,
 * horario y color. Cada hotel define los suyos.
 */
class ShiftType extends Model
{
    /** Colores permitidos (tokens del theme). */
    public const COLORS = ['primary', 'info', 'success', 'warning', 'pending', 'dark'];

    protected $fillable = [
        'property_id',
        'name',
        'starts_at',
        'ends_at',
        'color',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /** "07:00 – 15:00" (recorta los segundos). */
    public function timeLabel(): string
    {
        return substr((string) $this->starts_at, 0, 5).' – '.substr((string) $this->ends_at, 0, 5);
    }
}
