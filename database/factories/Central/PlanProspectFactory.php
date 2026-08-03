<?php

namespace Database\Factories\Central;

use App\Models\Central\PlanProspect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanProspect>
 */
class PlanProspectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'hotel_name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'rooms' => fake()->numberBetween(5, 120),
            'plan_key' => 'basic',
            'plan_label' => 'Básico',
            'message' => fake()->optional()->sentence(),
            'status' => 'new',
            'source' => 'landing',
        ];
    }
}
