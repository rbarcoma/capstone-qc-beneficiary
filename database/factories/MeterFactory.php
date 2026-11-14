<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Meter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meter>
 */
class MeterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'meralco_account_ref' => 'REF-'.fake()->unique()->numerify('########'),
            'tariff_class' => fake()->randomElement(['Commercial', 'Industrial']),
            'status' => 'active',
        ];
    }
}
