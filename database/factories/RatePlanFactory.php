<?php

namespace Database\Factories;

use App\Enums\RatePlanType;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\RatePlan>
 */
class RatePlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'room_type_id' => RoomType::factory(),
            'name' => 'Por noche',
            'type' => RatePlanType::Night,
            'duration_minutes' => null,
            'price' => 650,
            'active' => true,
        ];
    }

    public function block(int $minutes = 180, float $price = 250): static
    {
        return $this->state(fn () => [
            'name' => "Rato {$minutes} min",
            'type' => RatePlanType::Block,
            'duration_minutes' => $minutes,
            'duration_unit' => 'minute',
            'duration_value' => $minutes,
            'price' => $price,
        ]);
    }

    public function period(string $unit, int $value, float $price): static
    {
        return $this->state(fn () => [
            'name' => ucfirst($unit)." x{$value}",
            'type' => RatePlanType::Block,
            'duration_unit' => $unit,
            'duration_value' => $value,
            'duration_minutes' => null,
            'price' => $price,
        ]);
    }

    public function withMinAdvance(string $unit, int $value): static
    {
        return $this->state(fn () => [
            'min_advance_unit' => $unit,
            'min_advance_value' => $value,
        ]);
    }
}
