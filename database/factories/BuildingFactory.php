<?php

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Building '.fake()->unique()->company(),
            'code' => fake()->unique()->regexify('[A-Z]{6,10}'),
            'floor_area_m2' => null,
            'operating_hours' => null,
            'notes' => null,
        ];
    }
}
