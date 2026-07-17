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
 * resolución es el mismo para ambas — ver RatePlan::effectivePriceForDate().
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
        'price',
        'priority',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'price' => 'decimal:2',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function coversDate(CarbonInterface $date): bool
    {
        return $date->toDateString() >= $this->starts_on->toDateString()
            && $date->toDateString() <= $this->ends_on->toDateString();
    }
}
