<?php

namespace Database\Seeders;

use App\Models\Central\PlanProspect;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;

class PlanProspectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PlanProspect::factory()
            ->count(10)
            ->state(new Sequence(fn (Sequence $sequence) => [
                'status' => PlanProspect::STATUSES[$sequence->index % count(PlanProspect::STATUSES)],
                'contacted_at' => $sequence->index % count(PlanProspect::STATUSES) === 0
                    ? null
                    : now()->subDays($sequence->index),
            ]))
            ->create();
    }
}
