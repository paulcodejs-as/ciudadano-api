<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\Import;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory(),
            'user_id' => null,
            'file_name' => 'denuncias.csv',
            'status' => Import::STATUS_PENDING,
        ];
    }
}
