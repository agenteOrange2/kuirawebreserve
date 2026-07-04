<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'zone_id' => null,
            'room_type_id' => RoomType::factory(),
            'number' => (string) fake()->unique()->numberBetween(100, 999),
            'pos_x' => fake()->numberBetween(0, 800),
            'pos_y' => fake()->numberBetween(0, 600),
            'width' => 120,
            'height' => 80,
        ];
    }
}
