<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Hotel '.fake()->company(),
            'timezone' => 'America/Mexico_City',
            'address' => fake()->address(),
            'settings' => [],
        ];
    }
}
