<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\RoomType>
 */
class RoomTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->randomElement(['Sencilla', 'Doble', 'Suite', 'Jacuzzi']),
            'capacity' => fake()->numberBetween(1, 4),
            'base_price' => fake()->randomFloat(2, 400, 2500),
            'amenities' => fake()->randomElements(['tv', 'wifi', 'ac', 'minibar', 'jacuzzi'], 3),
        ];
    }
}
