<?php

namespace Database\Factories\Central;

use App\Models\Central\ProspectDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProspectDocument>
 */
class ProspectDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'service' => fake()->randomElement(['web', 'social', 'reservas', 'general']),
            'path' => 'prospect-docs/'.Str::random(12).'.pdf',
            'original_name' => Str::slug(fake()->words(2, true)).'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(50_000, 2_000_000),
            'sort' => 0,
        ];
    }
}
