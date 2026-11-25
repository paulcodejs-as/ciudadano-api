<?php

namespace App\Services\Importers;

use App\Models\District;
use App\Services\StatsCache;
use RuntimeException;

/**
 * Carga la población por distrito desde un CSV con una columna de ubigeo y
 * otra de población (INEI). Une por ubigeo, nunca por nombre.
 *
 * Columnas aceptadas (sin importar mayúsculas):
 * - ubigeo: UBIGEO, IDDIST, CODIGO, COD_UBIGEO
 * - población: POBLACION, POBLACIÓN, POPULATION, POB_TOTAL, TOTAL, POBLACION_2017
 *
 * Las filas con población vacía se omiten (no borran un valor ya cargado).
 */
class PopulationCsvImporter
{
    private const UBIGEO_COLUMNS = ['UBIGEO', 'IDDIST', 'CODIGO', 'COD_UBIGEO'];

    private const POPULATION_COLUMNS = ['POBLACION', 'POBLACIÓN', 'POPULATION', 'POB_TOTAL', 'TOTAL', 'POBLACION_2017'];

    /**
     * @return array{read:int, updated:int, skipped:int, log:list<string>}
     */
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No se encuentra el archivo CSV: {$path}");
        }

        $districts = District::pluck('id', 'ubigeo')->all();
        if ($districts === []) {
            throw new RuntimeException('No hay distritos en la base de datos. Corre antes ciudadano:import-districts.');
        }

        $handle = fopen($path, 'rb');
        $delimiter = $this->detectDelimiter((string) fgets($handle));
        rewind($handle);

        [$ubigeoCol, $populationCol] = $this->readHeader($handle, $delimiter);
        $prefixes = config('ciudadano.ubigeo_prefixes');

        $read = $updated = $skipped = 0;
        $empty = $unknown = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $read++;

            $rawUbigeo = trim((string) ($row[$ubigeoCol] ?? ''));
            if (! ctype_digit($rawUbigeo)) {
                $skipped++;

                continue;
            }
            $ubigeo = str_pad($rawUbigeo, 6, '0', STR_PAD_LEFT);

            if (! $this->inScope($ubigeo, $prefixes)) {
                $skipped++;

                continue;
            }

            // "1 038 495", "1,038,495" o "1038495" → 1038495.
            $digits = preg_replace('/\D/', '', preg_replace('/[.,]\d{1,2}$/', '', trim((string) ($row[$populationCol] ?? ''))));
            if ($digits === '' || (int) $digits === 0) {
                $skipped++;
                $empty[] = $ubigeo;

                continue;
            }

            if (! isset($districts[$ubigeo])) {
                $skipped++;
                $unknown[] = $ubigeo;

                continue;
            }

            District::whereKey($districts[$ubigeo])->update(['population' => (int) $digits]);
            $updated++;
        }

        fclose($handle);
        app(StatsCache::class)->flush();

        $log = ["Filas leídas: {$read}. Distritos actualizados: {$updated}. Omitidas: {$skipped}."];
        if ($empty) {
            $log[] = 'Sin población en el CSV: '.implode(', ', $empty);
        }
        if ($unknown) {
            $log[] = 'Ubigeos sin distrito importado: '.implode(', ', $unknown);
        }

        $missing = District::limaCallao()->whereNull('population')->pluck('ubigeo')->all();
        if ($missing) {
            $log[] = 'Distritos que siguen sin población: '.implode(', ', $missing);
        }

        return ['read' => $read, 'updated' => $updated, 'skipped' => $skipped, 'log' => $log];
    }

    /** @return array{0:int, 1:int} índices de las columnas ubigeo y población */
    private function readHeader($handle, string $delimiter): array
    {
        $header = fgetcsv($handle, 0, $delimiter);
        if (! is_array($header)) {
            throw new RuntimeException('El CSV está vacío.');
        }

        $names = array_map(
            fn ($h) => mb_strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header
        );

        $ubigeo = $this->findColumn($names, self::UBIGEO_COLUMNS);
        $population = $this->findColumn($names, self::POPULATION_COLUMNS);

        if ($ubigeo === null || $population === null) {
            throw new RuntimeException('El CSV necesita una columna de ubigeo y otra de población. Encontradas: '.implode(', ', $names));
        }

        return [$ubigeo, $population];
    }

    private function findColumn(array $names, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $names, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function inScope(string $ubigeo, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($ubigeo, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
