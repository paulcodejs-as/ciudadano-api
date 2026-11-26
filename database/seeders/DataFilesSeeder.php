<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Después de migrar (`migrate:fresh --seed`) vuelve a cargar los distritos y todos los
 * archivos SIDPOL que ya están guardados en el sistema. Se desactiva con SEED_DATA_FILES=false.
 */
class DataFilesSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('ciudadano.seed_data_files') || app()->runningUnitTests()) {
            return;
        }

        if ($this->command) {
            $this->command->call('ciudadano:load-data');
        } else {
            Artisan::call('ciudadano:load-data');
        }
    }
}
