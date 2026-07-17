<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ExperienceVehicle> */
class ExperienceVehicleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->randomElement(['Razer 1', 'Camioneta roja', 'Cuatrimoto 2']),
            'capacity' => 4,
            'active' => true,
            'sort_order' => 0,
        ];
    }
}
