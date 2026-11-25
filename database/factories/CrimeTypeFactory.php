<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CrimeType>
 */
class CrimeTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->word());

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'color' => $this->faker->hexColor(),
        ];
    }
}
