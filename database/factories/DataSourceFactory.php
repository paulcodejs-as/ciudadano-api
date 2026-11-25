<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DataSource>
 */
class DataSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->company(),
            'url' => $this->faker->url(),
            'cutoff_date' => null,
            'notes' => null,
        ];
    }
}
