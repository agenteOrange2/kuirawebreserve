<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => Product::TYPE_SIMPLE,
            'unit' => 'pieza',
            'price' => fake()->randomFloat(2, 20, 200),
            'cost' => 10,
            'track_stock' => true,
            'stock_qty' => 0,
            'active' => true,
        ];
    }

    public function composite(): static
    {
        return $this->state(fn () => [
            'type' => Product::TYPE_COMPOSITE,
            'track_stock' => false,
        ]);
    }
}
