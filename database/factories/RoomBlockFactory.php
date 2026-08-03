<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\RoomBlock>
 */
class RoomBlockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->addDays(2)->toDateString(),
            'reason' => 'Mantenimiento programado',
            'created_by' => null,
        ];
    }
}
