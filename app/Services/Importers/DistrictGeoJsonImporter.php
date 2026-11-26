<?php

namespace App\Services\Importers;

use App\Support\UbigeoScope;
use App\Services\StatsCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Importa los polígonos distritales desde peru_distrital_simple.geojson
 * (github.com/juaneladio/peru-geojson).
 *
 * - Solo guarda los ubigeos del ámbito (config ciudadano.ubigeo_prefixes).
 * - Une por ubigeo (IDDIST), rellenando el 0 inicial que a veces se pierde.
 * - Es idempotente: si el distrito ya existe actualiza nombre y geometría,
 *   sin tocar la población.
 * - Rellena geom_simplified y area_km2 a partir de geom.
 */
class DistrictGeoJsonImporter
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @return array{read:int, imported:int, skipped:int, log:list<string>}
     */
    public function import(string $path): array
    {
        $this->log = [];
        $features = $this->readFeatures($path);
        $prefixes = config('ciudadano.ubigeo_prefixes');

        $read = $imported = $skipped = 0;
        $importedIds = [];

        foreach ($features as $index => $feature) {
            $read++;
            $props = $feature['properties'] ?? [];
            // juaneladio usa IDDIST/NOMBDIST...; la capa INEI 2025 (ArcGIS) usa ubigeo/distrito/provincia/departamen.
            $props = [
                'IDDIST' => $props['IDDIST'] ?? $props['UBIGEO'] ?? $props['ubigeo'] ?? null,
                'NOMBDIST' => $props['NOMBDIST'] ?? $props['distrito'] ?? null,
                'NOMBPROV' => $props['NOMBPROV'] ?? $props['provincia'] ?? null,
                'NOMBDEP' => $props['NOMBDEP'] ?? $props['departamen'] ?? null,
                'POB' => $props['pob_2024'] ?? $props['POB_2024'] ?? null,
            ];
            $ubigeo = $this->normalizeUbigeo($props['IDDIST']);

            if ($ubigeo === null) {
                $skipped++;
                $this->log[] = "Feature #{$index}: sin ubigeo (IDDIST), se omite.";

                continue;
            }

            if (! $this->inScope($ubigeo, $prefixes)) {
                $skipped++;

                continue;
            }

            $geometry = $this->toMultiPolygon($feature['geometry'] ?? null);
            if ($geometry === null) {
                $skipped++;
                $this->log[] = "Ubigeo {$ubigeo}: geometría ausente o no poligonal, se omite.";

                continue;
            }

            try {
                $this->upsertDistrict($ubigeo, $props, $geometry);
            } catch (Throwable $e) {
                $skipped++;
                $this->log[] = "Ubigeo {$ubigeo}: MySQL rechazó la geometría ({$e->getMessage()}).";

                continue;
            }

            $imported++;
            $importedIds[] = $ubigeo;
        }

        $this->refreshDerivedColumns($importedIds);
        app(StatsCache::class)->flush();

        $this->log[] = "Leídos {$read}, importados {$imported}, omitidos {$skipped} (fuera del ámbito o inválidos).";

        return ['read' => $read, 'imported' => $imported, 'skipped' => $skipped, 'log' => $this->log];
    }

    /** @return list<array<string, mixed>> */
    private function readFeatures(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No se encuentra el archivo GeoJSON: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null)) {
            throw new RuntimeException('El archivo no es un GeoJSON FeatureCollection válido.');
        }

        return $data['features'];
    }

    private function normalizeUbigeo(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        // El 0 inicial se pierde a menudo (070101 → 70101).
        return str_pad($value, 6, '0', STR_PAD_LEFT);
    }

    /** Ámbito configurado (todo el país si no hay prefijos). */
    private function inScope(string $ubigeo, array $prefixes = []): bool
    {
        return UbigeoScope::contains($ubigeo);
    }

    /** Convierte Polygon a MultiPolygon (la columna es MULTIPOLYGON). */
    private function toMultiPolygon(?array $geometry): ?string
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if (! is_array($coordinates) || $coordinates === []) {
            return null;
        }

        return match ($type) {
            'MultiPolygon' => json_encode(['type' => 'MultiPolygon', 'coordinates' => $coordinates]),
            'Polygon' => json_encode(['type' => 'MultiPolygon', 'coordinates' => [$coordinates]]),
            default => null,
        };
    }

    private function upsertDistrict(string $ubigeo, array $props, string $geojson): void
    {
        $now = now()->toDateTimeString();

        // ST_GeomFromGeoJSON ya usa lng,lat. Opción 2: acepta y descarta coordenadas Z.
        // La capa INEI 2025 trae pob_2024: si viene, se guarda; si no, no se borra la población que ya hubiera.
        $population = isset($props['POB']) && (int) round((float) $props['POB']) > 0 ? (int) round((float) $props['POB']) : null;

        DB::statement(
            'INSERT INTO districts (ubigeo, name, province, department, population, geom, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ST_GeomFromGeoJSON(?, 2, 4326), ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                province = VALUES(province),
                department = VALUES(department),
                population = COALESCE(VALUES(population), population),
                geom = VALUES(geom),
                updated_at = VALUES(updated_at)',
            [
                $ubigeo,
                $this->text($props['NOMBDIST'] ?? null, $ubigeo),
                $this->text($props['NOMBPROV'] ?? null, $this->defaultRegion($ubigeo)),
                $this->text($props['NOMBDEP'] ?? null, $this->defaultRegion($ubigeo)),
                $population,
                $geojson,
                $now,
                $now,
            ]
        );
    }

    /**
     * Calcula geom_simplified y area_km2 distrito por distrito, para que una
     * geometría problemática no bloquee a las demás.
     *
     * @param list<string> $ubigeos
     */
    private function refreshDerivedColumns(array $ubigeos): void
    {
        $tolerance = (float) config('ciudadano.simplify_tolerance', 0.0005);

        foreach ($ubigeos as $ubigeo) {
            try {
                // ST_Simplify no acepta SRS geográficos: se pasa a SRID 0 y se vuelve a 4326.
                // Si el resultado deja de ser un MULTIPOLYGON válido (colapsa o se autointersecta),
                // se usa geom tal cual.
                DB::statement(
                    "UPDATE districts
                     SET geom_simplified = IF(
                         ST_GeometryType(ST_Simplify(ST_SRID(geom, 0), ?)) = 'MULTIPOLYGON'
                             AND ST_IsValid(ST_Simplify(ST_SRID(geom, 0), ?)),
                         ST_SRID(ST_Simplify(ST_SRID(geom, 0), ?), 4326),
                         geom)
                     WHERE ubigeo = ?",
                    [$tolerance, $tolerance, $tolerance, $ubigeo]
                );
            } catch (Throwable $e) {
                DB::update('UPDATE districts SET geom_simplified = geom WHERE ubigeo = ?', [$ubigeo]);
                $this->log[] = "Ubigeo {$ubigeo}: no se pudo simplificar, se usa la geometría completa.";
            }

            try {
                // En SRID 4326 ST_Area devuelve m² sobre el elipsoide.
                DB::update('UPDATE districts SET area_km2 = ROUND(ST_Area(geom) / 1000000, 2) WHERE ubigeo = ?', [$ubigeo]);
            } catch (Throwable $e) {
                $this->log[] = "Ubigeo {$ubigeo}: no se pudo calcular el área ({$e->getMessage()}).";
            }
        }
    }

    private function text(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value === '' ? $fallback : mb_strtoupper($value);
    }

    private function defaultRegion(string $ubigeo): string
    {
        return str_starts_with($ubigeo, '07') ? 'CALLAO' : 'LIMA';
    }
}
