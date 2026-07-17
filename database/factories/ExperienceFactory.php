<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Experience> */
class ExperienceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->randomElement(['Recorrido en cuatrimoto', 'Tour por la sierra', 'Cabalgata al mirador']),
            'description' => fake()->sentence(),
            'includes' => ['Guía certificado', 'Equipo de seguridad'],
            'duration_minutes' => 120,
            'pricing_mode' => 'per_person',
            'price' => 450,
            'min_people' => 1,
            'max_people' => null,
            'active' => true,
            'sort_order' => 0,
        ];
    }
}
