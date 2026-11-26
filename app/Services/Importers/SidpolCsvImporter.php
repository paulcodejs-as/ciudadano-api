<?php

namespace App\Services\Importers;

use App\Support\UbigeoScope;
use App\Models\CrimeStatMonthly;
use App\Models\CrimeType;
use App\Models\DataSource;
use App\Models\District;
use App\Models\Import;
use App\Services\StatsCache;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;

/**
 * Importa "Denuncias Policiales" (SIDPOL), en CSV o Excel .xlsx, a crime_stats_monthly.
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

        $import->update([
            'status' => Import::STATUS_RUNNING,
            'file_hash' => is_file($path) ? sha1_file($path) : null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

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
            throw new RuntimeException("No se encuentra el archivo: {$path}");
        }

        $districts = District::pluck('id', 'ubigeo')->all();
        if ($districts === []) {
            throw new RuntimeException('No hay distritos en la base de datos. Corre antes ciudadano:import-districts.');
        }

        $crimeTypes = CrimeType::pluck('id', 'slug')->all();
        $prefixes = config('ciudadano.ubigeo_prefixes');

        $rows = $this->rows($path);
        $columns = $this->readHeader($rows);
        $rows->next();

        $read = $imported = 0;
        $skip = ['out_of_scope' => 0, 'unknown_district' => 0, 'unknown_type' => 0, 'invalid' => 0];
        $unknownTypes = [];
        $unknownDistricts = [];
        $totals = []; // "district|type|year|month" => count

        for (; $rows->valid(); $rows->next()) {
            $row = $rows->current();
            if ($row === [null] || $row === [] || $row === ['']) {
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

    /**
     * Revisa solo la cabecera (para validar una subida antes de encolarla).
     *
     * @return string|null mensaje de error, o null si el archivo tiene las columnas necesarias
     */
    public function headerError(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return 'No se pudo leer el archivo.';
        }

        try {
            $this->readHeader($this->rows($path));

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            return 'No se pudo leer el archivo: '.$e->getMessage();
        }
    }

    /** "xlsx" si el archivo es un Excel (ZIP), "xls" si es Excel antiguo, si no "csv". */
    public static function detectFormat(string $path): string
    {
        $magic = (string) @file_get_contents($path, false, null, 0, 8);

        return match (true) {
            str_starts_with($magic, "PK\x03\x04") => 'xlsx',
            str_starts_with($magic, "\xD0\xCF\x11\xE0") => 'xls',
            default => 'csv',
        };
    }

    /**
     * Filas del archivo como listas de textos, cabecera incluida. Lee CSV (cualquier
     * separador) o Excel .xlsx (primera hoja) sin cargarlo entero en memoria.
     *
     * @return Generator<int, list<string|null>>
     */
    private function rows(string $path): Generator
    {
        $format = self::detectFormat($path);

        if ($format === 'xls') {
            throw new RuntimeException('El Excel antiguo (.xls) no se puede leer: guárdalo como .xlsx o CSV.');
        }

        if ($format === 'xlsx') {
            $reader = new XlsxReader();
            $reader->open($path);
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        yield array_map(fn ($cell) => $this->cellToString($cell->getValue()), $row->getCells());
                    }
                    break; // solo la primera hoja
                }
            } finally {
                $reader->close();
            }

            return;
        }

        $handle = fopen($path, 'rb');
        try {
            $delimiter = $this->detectDelimiter((string) fgets($handle));
            rewind($handle);
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /** Excel guarda números (150101, 2024.0) y fechas: todo pasa a texto como en el CSV. */
    private function cellToString(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value => (string) (int) $value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /** @return array<string, int> nombre de columna en mayúsculas => índice */
    private function readHeader(Generator $rows): array
    {
        $header = $rows->current();
        if (! is_array($header)) {
            throw new RuntimeException('El archivo está vacío.');
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
            throw new RuntimeException('Faltan columnas en el archivo: '.implode(', ', $missing)
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

    /** Ámbito configurado (todo el país si no hay prefijos). */
    private function inScope(string $ubigeo, array $prefixes = []): bool
    {
        return UbigeoScope::contains($ubigeo);
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
