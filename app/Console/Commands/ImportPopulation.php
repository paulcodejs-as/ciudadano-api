<?php

namespace App\Console\Commands;

use App\Services\Importers\PopulationCsvImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportPopulation extends Command
{
    protected $signature = 'ciudadano:import-population
                            {path=database/data/poblacion_distritos.csv : CSV con columnas ubigeo y poblacion}';

    protected $description = 'Carga la población por distrito para calcular la tasa por 10 000 habitantes';

    public function handle(PopulationCsvImporter $importer): int
    {
        $path = is_file($this->argument('path')) ? $this->argument('path') : base_path($this->argument('path'));
        $this->info("Cargando población desde {$path} ...");

        try {
            $result = $importer->import($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['log'] as $line) {
            $this->line($line);
        }

        if ($result['updated'] === 0) {
            $this->warn('No se actualizó ningún distrito. ¿Llenaste la columna "poblacion"?');

            return self::FAILURE;
        }

        $this->info('Fuente registrada: '.config('ciudadano.population_source'));

        return self::SUCCESS;
    }
}
