<?php

namespace Database\Factories;

use App\Models\CrimeType;
use App\Models\DataSource;
use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CrimeStatMonthly>
 */
class CrimeStatMonthlyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'district_id' => District::factory(),
            'crime_type_id' => CrimeType::factory(),
            'data_source_id' => DataSource::factory(),
            'year' => $this->faker->numberBetween(2018, 2025),
            'month' => $this->faker->numberBetween(1, 12),
            'count' => $this->faker->numberBetween(0, 500),
        ];
    }
}
