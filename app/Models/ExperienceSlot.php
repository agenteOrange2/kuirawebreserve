<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Horario recurrente de una experiencia ("10:00 con el razer y la
 * camioneta"). Junto con Experience::operating_days define la
 * programación semanal; GenerateExperienceSessions la materializa en
 * sesiones reales (cupo congelado) para el horizonte de venta.
 */
class ExperienceSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'experience_id',
        'start_time',
        'vehicle_ids',
        'capacity',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_ids' => 'array',
            'capacity' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExperienceSession::class);
    }

    /** Vehículos vivos asignados a este horario. */
    public function vehicles(): Collection
    {
        if (empty($this->vehicle_ids)) {
            return collect();
        }

        return ExperienceVehicle::query()
            ->whereIn('id', $this->vehicle_ids)
            ->where('active', true)
            ->get();
    }

    /**
     * Cupo que tendrán las sesiones generadas: override manual, o la suma
     * de capacidad de los vehículos del horario. `$vehicles` permite pasar
     * la flota ya cargada (keyBy id) para no consultar por horario.
     */
    public function effectiveCapacity(?Collection $vehicles = null): int
    {
        if ($this->capacity !== null) {
            return (int) $this->capacity;
        }

        $fleet = $vehicles !== null
            ? collect($this->vehicle_ids ?? [])->map(fn ($id) => $vehicles->get($id))->filter()
            : $this->vehicles();

        return (int) $fleet->sum('capacity');
    }
}
