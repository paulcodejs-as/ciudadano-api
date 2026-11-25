<?php

namespace Database\Seeders;

use App\Models\CrimeType;
use Illuminate\Database\Seeder;

/** Las 7 modalidades de "Denuncias Policiales" (SIDPOL). */
class CrimeTypeSeeder extends Seeder
{
    public const TYPES = [
        ['slug' => 'robo', 'name' => 'Robo', 'color' => '#D7263D'],
        ['slug' => 'hurto', 'name' => 'Hurto', 'color' => '#F46036'],
        ['slug' => 'extorsion', 'name' => 'Extorsión', 'color' => '#7B2CBF'],
        ['slug' => 'estafa', 'name' => 'Estafa', 'color' => '#1B998B'],
        ['slug' => 'secuestro', 'name' => 'Secuestro', 'color' => '#2E294E'],
        ['slug' => 'violencia-contra-la-mujer', 'name' => 'Violencia contra la mujer', 'color' => '#C5283D'],
        ['slug' => 'otros', 'name' => 'Otros', 'color' => '#6C757D'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            CrimeType::updateOrCreate(['slug' => $type['slug']], $type);
        }
    }
}
