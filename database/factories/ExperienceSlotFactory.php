<?php

namespace Database\Factories;

use App\Models\Experience;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ExperienceSlot> */
class ExperienceSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'experience_id' => Experience::factory(),
            'start_time' => '10:00',
            'vehicle_ids' => null,
            'capacity' => 10,
            'active' => true,
        ];
    }
}
