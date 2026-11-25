<?php

namespace App\Services\Importers;

use App\Models\CrimeStatMonthly;
use App\Models\CrimeType;
use App\Models\DataSource;
use App\Models\District;
use App\Models\Import;
use App\Services\StatsCache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importa el CSV "Denuncias Policiales" (SIDPOL) a crime_stats_monthly.
 *
 * Columnas esperadas (sin importar mayúsculas ni orden):
 * ANIO, MES, UBIGEO_HECHO, P_MODALIDADES, CANTIDAD.
 *
 * - Solo el ámbito (config ciudadano.ubigeo_prefixes) y distritos ya importados.
 * - Suma las filas repetidas de la misma clave y luego hace upsert sobre la
 *   clave única reemplazando el conteo: correr dos veces da el mismo resultado.
 * - Deja la traza en la tabla imports.
 */
class SidpolCsvImporter
{
    private const REQUIRED_COLUMNS = ['ANIO', 'MES', 'UBIGEO_HECHO', 'P_MODALIDADES', 'CANTIDAD'];

    /** Nombres alternativos que usa el portal según el archivo. */
    private const COLUMN_ALIASES = [
        'ANIO' => ['ANIO_REGISTRO', 'AÑO', 'ANIO_HECHO'],
        'MES' => ['MES_REGISTRO', 'MES_HECHO'],
        'UBIGEO_HECHO' => ['UBIGEO'],
    ];

    private const MONTH_NAMES = [
        'ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6,
        'JULIO' => 7, 'AGOSTO' => 8, 'SETIEMBRE' => 9, 'SEPTIEMBRE' => 9, 'OCTUBRE' => 10,
        'NOVIEMBRE' => 11, 'DICIEMBRE' => 12,
    ];

    /**
     * @param  Import|null  $import  Registro ya creado (p. ej. desde el panel); si es null se crea uno.
     */
    public function import(string $path, ?Import $import = null, ?int $userId = null): Import
    {
        $source = DataSource::where('slug', DataSource::SIDPOL)->firstOrFail();

        $import ??= Import::create([
            'data_source_id' => $source->id,
            'user_id' => $userId,
            'file_name' => basename($path),
            'status' => Import::STATUS_PENDING,
        ]);

        $import->update(['status' => Import::STATUS_RUNNING, 'started_at' => now(), 'finished_at' => null]);

        try {
            $stats = $this->process($path, $source);
        } catch (Throwable $e) {
            $import->update([
                'status' => Import::STATUS_FAILED,
                'log' => 'Error: '.$e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $import->update([
            'status' => Import::STATUS_DONE,
            'rows_read' => $stats['read'],
            'rows_imported' => $stats['imported'],
            'rows_skipped' => $stats['read'] - $stats['imported'],
            'log' => implode("\n", $stats['log']),
            'finished_at' => now(),
        ]);

        return $import->fresh();
    }

    /**
     * @return array{read:int, imported:int, log:list<string>}
     */
    private function process(string $path, DataSource $source): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No se encuentra el archivo CSV: {$path}");
        }

        $districts = District::pluck('id', 'ubigeo')->all();
        if ($districts === []) {
            throw new RuntimeException('No hay distritos en la base de datos. Corre antes ciudadano:import-districts.');
        }

        $crimeTypes = CrimeType::pluck('id', 'slug')->all();
        $prefixes = config('ciudadano.ubigeo_prefixes');

        $handle = fopen($path, 'rb');
        $firstLine = (string) fgets($handle);
        $delimiter = $this->detectDelimiter($firstLine);
        rewind($handle);

        $columns = $this->readHeader($handle, $delimiter);

        $read = $imported = 0;
        $skip = ['out_of_scope' => 0, 'unknown_district' => 0, 'unknown_type' => 0, 'invalid' => 0];
        $unknownTypes = [];
        $unknownDistricts = [];
        $totals = []; // "district|type|year|month" => count

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null] || $row === []) {
                continue; // línea vacía
            }
            $read++;

            $ubigeo = $this->normalizeUbigeo($row[$columns['UBIGEO_HECHO']] ?? '');
            if ($ubigeo === null) {
                $skip['invalid']++;

                continue;
            }
            if (! $this->inScope($ubigeo, $prefixes)) {
                $skip['out_of_scope']++;

                continue;
            }

            $districtId = $districts[$ubigeo] ?? null;
            if ($districtId === null) {
                $skip['unknown_district']++;
                $unknownDistricts[$ubigeo] = true;

                continue;
            }

            $rawType = $this->toUtf8(trim((string) ($row[$columns['P_MODALIDADES']] ?? '')));
            $typeId = $this->matchCrimeType($rawType, $crimeTypes);
            if ($typeId === null) {
                $skip['unknown_type']++;
                $unknownTypes[$rawType] = true;

                continue;
            }

            $year = (int) trim((string) ($row[$columns['ANIO']] ?? ''));
            $month = $this->parseMonth($row[$columns['MES']] ?? '');
            if ($year < 2000 || $year > 2100 || $month === null) {
                $skip['invalid']++;

                continue;
            }

            // cantidad puede venir decimal ("3.0") o vacía.
            $count = (int) round((float) str_replace(',', '.', trim((string) ($row[$columns['CANTIDAD']] ?? ''))));
            if ($count < 0) {
                $skip['invalid']++;

                continue;
            }

            $key = "{$districtId}|{$typeId}|{$year}|{$month}";
            $totals[$key] = ($totals[$key] ?? 0) + $count;
            $imported++;
        }

        fclose($handle);

        $this->upsert($totals, $source->id);
        app(StatsCache::class)->flush();

        $log = [
            "Filas leídas: {$read}. Filas usadas: {$imported}. Registros mensuales guardados: ".count($totals).'.',
            "Omitidas fuera de Lima/Callao: {$skip['out_of_scope']}.",
            "Omitidas por distrito no importado: {$skip['unknown_district']}.",
            "Omitidas por modalidad desconocida: {$skip['unknown_type']}.",
            "Omitidas por datos inválidos (año, mes o ubigeo): {$skip['invalid']}.",
        ];
        if ($unknownTypes !== []) {
            $log[] = 'Modalidades desconocidas: '.implode(', ', array_slice(array_keys($unknownTypes), 0, 20));
        }
        if ($unknownDistricts !== []) {
            $log[] = 'Ubigeos sin polígono: '.implode(', ', array_slice(array_keys($unknownDistricts), 0, 50));
        }

        return ['read' => $read, 'imported' => $imported, 'log' => $log];
    }

    /** @param array<string, int> $totals */
    private function upsert(array $totals, int $sourceId): void
    {
        $now = now();
        $rows = [];

        foreach ($totals as $key => $count) {
            [$districtId, $typeId, $year, $month] = array_map('intval', explode('|', $key));
            $rows[] = [
                'district_id' => $districtId,
                'crime_type_id' => $typeId,
                'data_source_id' => $sourceId,
                'year' => $year,
                'month' => $month,
                'count' => $count,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, (int) config('ciudadano.import_chunk_size', 1000)) as $chunk) {
            CrimeStatMonthly::upsert(
                $chunk,
                ['district_id', 'crime_type_id', 'data_source_id', 'year', 'month'],
                ['count', 'updated_at']
            );
        }
    }

    /** @return array<string, int> nombre de columna en mayúsculas => índice */
    private function readHeader($handle, string $delimiter): array
    {
        $header = fgetcsv($handle, 0, $delimiter);
        if (! is_array($header)) {
            throw new RuntimeException('El CSV está vacío.');
        }

        $columns = [];
        foreach ($header as $index => $name) {
            // Quita BOM UTF-8 y espacios.
            $name = strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)));
            $columns[$name] = $index;
        }

        // Los archivos anuales recientes (p. ej. 2026) usan ANIO_REGISTRO / MES_REGISTRO.
        foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (! isset($columns[$canonical]) && isset($columns[$alias])) {
                    $columns[$canonical] = $columns[$alias];
                }
            }
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columns));
        if ($missing !== []) {
            throw new RuntimeException('Faltan columnas en el CSV: '.implode(', ', $missing)
                .'. Encontradas: '.implode(', ', array_keys($columns)));
        }

        return $columns;
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), '|' => substr_count($line, '|')];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    private function normalizeUbigeo(mixed $value): ?string
    {
        $value = trim((string) $value);
        // A veces llega como número decimal ("150101.0").
        if (preg_match('/^(\d+)(\.0+)?$/', $value, $m) !== 1) {
            return null;
        }

        // El portal pierde el 0 inicial: siempre rellenar a 6 dígitos.
        return str_pad($m[1], 6, '0', STR_PAD_LEFT);
    }

    /** @param list<string> $prefixes */
    private function inScope(string $ubigeo, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($ubigeo, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function parseMonth(mixed $value): ?int
    {
        $value = strtoupper(trim($this->toUtf8((string) $value)));
        $month = ctype_digit($value) ? (int) $value : (self::MONTH_NAMES[$value] ?? 0);

        return $month >= 1 && $month <= 12 ? $month : null;
    }

    /**
     * "Extorsión", "EXTORSION", "Violencia contra la mujer e integrantes..." → id de la modalidad.
     *
     * @param  array<string, int>  $crimeTypes  slug => id
     */
    private function matchCrimeType(string $raw, array $crimeTypes): ?int
    {
        $slug = Str::slug($raw);
        if ($slug === '') {
            return null;
        }
        if (isset($crimeTypes[$slug])) {
            return $crimeTypes[$slug];
        }

        // Variantes más largas del mismo nombre ("violencia-contra-la-mujer-e-integrantes-...").
        foreach ($crimeTypes as $knownSlug => $id) {
            if (str_starts_with($slug, $knownSlug.'-')) {
                return $id;
            }
        }

        return null;
    }

    /** El portal a veces publica en Windows-1252 en vez de UTF-8. */
    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
