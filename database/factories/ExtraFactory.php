<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Extra> */
class ExtraFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->randomElement(['Decoración romántica', 'Desayuno a la habitación', 'Late checkout', 'Cama extra']),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 100, 800),
            'active' => true,
            'sort_order' => 0,
        ];
    }
}
