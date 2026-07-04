<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Ingredient>
 */
class IngredientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->word(),
            'unit' => 'pieza',
            'stock_qty' => 0,
            'reorder_point' => null,
            'cost' => fake()->randomFloat(2, 5, 50),
        ];
    }
}
