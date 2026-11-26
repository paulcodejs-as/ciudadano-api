<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CrimeTypeSeeder::class,
            DataSourceSeeder::class,
            AdminUserSeeder::class,
            DataFilesSeeder::class, // distritos y archivos SIDPOL ya guardados
        ]);
    }
}
