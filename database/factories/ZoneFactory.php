<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Zone>
 */
class ZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Piso '.fake()->unique()->numberBetween(1, 20),
            'sort_order' => 0,
        ];
    }
}
