<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehículo de la flota para experiencias (razer, camioneta, cuatrimoto...)
 * con su capacidad de personas. Catálogo de la propiedad: el mismo
 * vehículo puede asignarse a horarios de varias experiencias; el cupo se
 * congela en la sesión al generarla, así que cambiarlo aquí solo afecta
 * sesiones futuras regeneradas.
 */
class ExperienceVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'name',
        'capacity',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
