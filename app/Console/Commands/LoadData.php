<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\District;
use App\Models\Import;
use App\Services\Importers\SidpolCsvImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Vuelve a cargar todo lo que ya está en el sistema: polígonos (si faltan), departamentos
 * y provincias, y cada archivo SIDPOL (CSV o .xlsx) guardado en storage/app/data o subido
 * por el panel (storage/app/imports). Pensado para después de `migrate:fresh`.
 */
class LoadData extends Command
{
    protected $signature = 'ciudadano:load-data
                            {--force : Vuelve a importar distritos y archivos aunque ya estén cargados}';

    protected $description = 'Carga distritos, regiones y todos los archivos SIDPOL guardados (CSV o Excel)';

    public function handle(SidpolCsvImporter $importer): int
    {
        $force = (bool) $this->option('force');

        if (! $this->ensureDistricts($force)) {
            return self::FAILURE;
        }

        $files = $this->sidpolFiles($importer);
        if ($files === []) {
            $this->warn('No hay archivos SIDPOL (CSV o .xlsx) en: '.implode(', ', (array) config('ciudadano.data_paths')));

            return self::SUCCESS;
        }

        $loaded = $skipped = $failed = 0;
        foreach ($files as $hash => $path) {
            $name = basename($path);
            if (! $force && Import::where('file_hash', $hash)->where('status', Import::STATUS_DONE)->exists()) {
                $this->line("  · {$name}: ya estaba cargado.");
                $skipped++;

                continue;
            }

            $this->line("  · {$name}: importando ...");
            try {
                $import = $importer->import($path);
                $this->info("    {$import->rows_imported} filas usadas de {$import->rows_read}.");
                $loaded++;
            } catch (Throwable $e) {
                $this->error("    {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Archivos SIDPOL: {$loaded} cargados, {$skipped} ya cargados, {$failed} con error.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Importa los polígonos si faltan y arma departamentos y provincias. */
    private function ensureDistricts(bool $force): bool
    {
        $imported = false;
        if ($force || District::count() === 0) {
            $geojson = (string) config('ciudadano.districts_geojson');
            if (! is_file($geojson)) {
                $this->error("No hay distritos y no se encuentra {$geojson}. Corre antes: php artisan ciudadano:fetch-districts");

                return false;
            }
            if ($this->call('ciudadano:import-districts', ['path' => $geojson]) !== self::SUCCESS) {
                return false;
            }
            $imported = true;
        }

        if ($imported || Department::count() === 0) {
            if ($this->call('ciudadano:build-regions') !== self::SUCCESS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Archivos SIDPOL válidos de las carpetas configuradas, sin repetidos (por contenido),
     * del más antiguo al más reciente: si dos archivos traen el mismo mes, gana el último.
     *
     * @return array<string, string> sha1 => ruta
     */
    private function sidpolFiles(SidpolCsvImporter $importer): array
    {
        $candidates = [];
        foreach ((array) config('ciudadano.data_paths') as $dir) {
            foreach (glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt', 'xlsx'], true)) {
                    $candidates[] = $path;
                }
            }
        }
        usort($candidates, fn ($a, $b) => [filemtime($a), $a] <=> [filemtime($b), $b]);

        $files = [];
        foreach ($candidates as $path) {
            // Otros CSV (p. ej. correcciones de población) no tienen las columnas SIDPOL.
            if ($importer->headerError($path) !== null) {
                continue;
            }
            $files[sha1_file($path)] ??= $path;
        }

        return $files;
    }
}
