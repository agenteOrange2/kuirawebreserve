<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Asignación del rol semanal: a alguien le toca un tipo de turno en una
 * fecha concreta. Ese "alguien" puede ser del panel (User), una camarista
 * (Housekeeper) o un técnico (Technician) — los dos últimos trabajan por
 * turno igual que recepción aunque no tengan cuenta.
 *
 * No confundir con Shift: aquel es la asistencia CON CAJA (fondo inicial y
 * corte) y por eso sigue siendo solo de usuarios; esto es el rol, o sea
 * quién debería estar.
 */
class ShiftAssignment extends Model
{
    /** Áreas que se programan, por la clave corta que viaja al front. */
    public const KINDS = [
        'user' => User::class,
        'housekeeper' => Housekeeper::class,
        'technician' => Technician::class,
    ];

    protected $fillable = [
        'property_id',
        'assignable_type',
        'assignable_id',
        'shift_type_id',
        'date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Clave corta del área ('user' | 'housekeeper' | 'technician'). */
    public function kind(): string
    {
        return array_search($this->assignable_type, self::KINDS, true) ?: 'user';
    }

    /** Identificador estable para agrupar el rol en la cuadrícula. */
    public function slot(): string
    {
        return $this->kind().':'.$this->assignable_id;
    }

    public function assigneeName(): ?string
    {
        return $this->assignable?->name;
    }

    /** Clase del modelo a partir de la clave corta, o null si no aplica. */
    public static function classFor(string $kind): ?string
    {
        return self::KINDS[$kind] ?? null;
    }
}
