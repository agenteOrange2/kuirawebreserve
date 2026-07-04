<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación del rol semanal: a un usuario le toca un tipo de turno en una
 * fecha concreta. El registro real de asistencia sigue siendo Shift
 * (abrir/cerrar turno).
 */
class ShiftAssignment extends Model
{
    protected $fillable = [
        'property_id',
        'user_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
