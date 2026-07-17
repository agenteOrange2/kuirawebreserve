<?php

namespace Database\Factories;

use App\Models\Experience;
use App\Models\ExperienceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExperienceSession> */
class ExperienceSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'experience_id' => Experience::factory(),
            'starts_at' => now()->addDays(3)->setTime(10, 0),
            'capacity' => 10,
            'status' => ExperienceSession::STATUS_SCHEDULED,
        ];
    }
}
