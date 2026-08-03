<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temporada o promo de una tarifa (spec-motor-reservas-web E0.5): un rango
 * de fechas con un precio que SUSTITUYE al de la tarifa mientras esté
 * vigente. `kind` es solo etiqueta (temporada vs promo); el mecanismo de
 * resolución es el mismo para ambas — ver RatePlan::activeSeasonFor().
 *
 * `weekdays` (0=domingo..6=sábado, null = todos los días) acota la
 * temporada a ciertos días: dentro del mismo rango, solo las noches que
 * caen en esos días usan su precio. Sin fechas (starts/ends null) es una
 * regla recurrente permanente: "todos los viernes y sábados".
 */
class RatePlanSeason extends Model
{
    /** @use HasFactory<\Database\Factories\RatePlanSeasonFactory> */
    use HasFactory;

    public const KIND_SEASON = 'season';

    public const KIND_PROMO = 'promo';

    protected $fillable = [
        'rate_plan_id',
        'name',
        'kind',
        'starts_on',
        'ends_on',
        'weekdays',
        'price',
        'priority',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weekdays' => 'array',
            'price' => 'decimal:2',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * ¿La temporada rige el precio de esa fecha? Debe caer dentro del rango
     * (si lo hay — null en un extremo es abierto) Y en los días de la
     * semana marcados (null/vacío = toda la semana).
     */
    public function coversDate(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        if ($this->starts_on !== null && $day < $this->starts_on->toDateString()) {
            return false;
        }

        if ($this->ends_on !== null && $day > $this->ends_on->toDateString()) {
            return false;
        }

        return $this->appliesOnWeekday($date);
    }

    public function appliesOnWeekday(CarbonInterface $date): bool
    {
        if (empty($this->weekdays)) {
            return true;
        }

        // Carbon usa la misma convención: dayOfWeek 0=domingo..6=sábado.
        return in_array($date->dayOfWeek, array_map('intval', $this->weekdays), true);
    }
}
