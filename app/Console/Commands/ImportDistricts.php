<?php

namespace App\Console\Commands;

use App\Services\Importers\DistrictGeoJsonImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportDistricts extends Command
{
    protected $signature = 'ciudadano:import-districts
                            {path=storage/app/data/lima_callao_distritos_inei2025.geojson : Ruta al GeoJSON distrital (IDDIST, NOMBDIST, NOMBPROV, NOMBDEP)}';

    protected $description = 'Importa los polígonos de los distritos de Lima Metropolitana y Callao desde un GeoJSON';

    public function handle(DistrictGeoJsonImporter $importer): int
    {
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
