<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder
{
    public function run(): void
    {
        DataSource::updateOrCreate(['slug' => DataSource::SIDPOL], [
            'name' => 'Denuncias Policiales (SIDPOL, PNP/Mininter)',
            'url' => 'https://www.datosabiertos.gob.pe/dataset/denuncias-policiales',
            'notes' => 'Conteos mensuales de denuncias registradas por distrito y modalidad. '
                .'Desde nov 2024 no incluye denuncias virtuales de robo/hurto.',
        ]);
    }
}
