<?php

namespace Database\Factories;

use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\RatePlanSeason>
 */
class RatePlanSeasonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rate_plan_id' => RatePlan::factory(),
            'name' => 'Temporada alta',
            'kind' => RatePlanSeason::KIND_SEASON,
            'starts_on' => now()->addMonth()->startOfMonth(),
            'ends_on' => now()->addMonth()->endOfMonth(),
            'price' => 1200,
            'priority' => 0,
            'active' => true,
        ];
    }

    public function promo(): static
    {
        return $this->state(fn () => ['kind' => RatePlanSeason::KIND_PROMO, 'name' => 'Promo lanzamiento']);
    }
}
