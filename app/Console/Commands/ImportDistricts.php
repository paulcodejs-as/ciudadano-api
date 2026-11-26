<?php

namespace App\Console\Commands;

use App\Services\Importers\DistrictGeoJsonImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportDistricts extends Command
{
    protected $signature = 'ciudadano:import-districts
                            {path=storage/app/data/peru_distritos_inei2025.geojson : Ruta al GeoJSON distrital (de ciudadano:fetch-districts)}';

    protected $description = 'Importa los polígonos (y la población pob_2024, si viene) de los distritos desde un GeoJSON';

    public function handle(DistrictGeoJsonImporter $importer): int
    {
        // El GeoJSON nacional pesa ~66 MB: json_decode necesita bastante memoria.
        ini_set("memory_limit", "2048M");

        $path = $this->resolvePath($this->argument('path'));
        $this->info("Importando distritos desde {$path} ...");

        try {
            $result = $importer->import($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['log'] as $line) {
            $this->line($line);
        }

        if ($result['imported'] === 0) {
            $this->warn('No se importó ningún distrito. ¿Es el GeoJSON distrital correcto?');

            return self::FAILURE;
        }

        $this->info('Listo.');

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return is_file($path) ? $path : base_path($path);
    }
}
