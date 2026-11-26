<?php

namespace App\Services;

use App\Models\District;
use App\Support\Level;
use App\Support\StatsFilters;
use Illuminate\Support\Facades\DB;

class GeoService
{
    public function __construct(
        private readonly CrimeStatsService $stats,
        private readonly StatsCache $cache,
    ) {
    }

    /** Distrito del ámbito que contiene el punto, o null. */
    public function locate(float $lat, float $lng): ?District
    {
        // En SRID 4326 MySQL usa lat-long: se fuerza el orden "lng lat".
        return District::query()
            ->withoutGeometry()
            ->inScope()
            ->whereRaw(
                "ST_Contains(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
                [sprintf('POINT(%.7F %.7F)', $lng, $lat)]
            )
            ->first();
    }

    /**
     * Un punto por región (su centroide) con sus totales: para el mapa de calor y las burbujas.
     * El centroide solo ubica a la región entera; no indica dónde ocurrieron las denuncias.
     *
     * @return array{metric: string, items: list<array>}
     */
    public function points(StatsFilters $filters, string $level, ?string $parent = null): array
    {
        $ranking = $this->stats->rankings($filters, $level, $parent);
        $centroids = $this->centroids($level);

        $items = [];
        foreach ($ranking['items'] as $item) {
            if (! isset($centroids[$item['code']])) {
                continue;
            }
            [$lng, $lat] = $centroids[$item['code']];
            $items[] = [
                'code' => $item['code'],
                'level' => $level,
                'name' => $item['name'],
                'lat' => $lat,
                'lng' => $lng,
                'population' => $item['population'],
                'total' => $item['total'],
                'rate' => $item['rate'],
                'change_pct' => $item['change_pct'],
                'value' => $item['value'],
                'rank' => $item['rank'],
            ];
        }

        return ['metric' => $ranking['metric'], 'items' => $items];
    }

    /**
     * Centroide de cada región, calculado en SRID 0 (X = lng, Y = lat): MySQL no calcula
     * ST_Centroid en SRS geográficos. En distritos muy cóncavos puede caer fuera del polígono.
     *
     * @return array<string, array{0: float, 1: float}> código => [lng, lat]
     */
    public function centroids(string $level): array
    {
        return $this->cache->remember("centroids:{$level}", function () use ($level) {
            [$table, $column, $geom] = match ($level) {
                Level::DEPARTMENT => ['departments', 'code', 'geom_simplified'],
                Level::PROVINCE => ['provinces', 'code', 'geom_simplified'],
                default => ['districts', 'ubigeo', 'COALESCE(geom_simplified, geom)'],
            };

            $rows = DB::select(
                "SELECT code, ROUND(ST_X(c), 5) AS lng, ROUND(ST_Y(c), 5) AS lat FROM (
                    SELECT {$column} AS code, ST_Centroid(ST_SRID({$geom}, 0)) AS c FROM {$table} WHERE {$geom} IS NOT NULL
                ) AS x"
            );

            $out = [];
            foreach ($rows as $r) {
                $out[$r->code] = [(float) $r->lng, (float) $r->lat];
            }

            return $out;
        });
    }

    /**
     * FeatureCollection con las geometrías simplificadas y los totales del rango.
     * Los metadatos van en "properties" de la colección.
     */
    public function districtsFeatureCollection(StatsFilters $filters, ?string $parent = null): array
    {
        return $this->regionsFeatureCollection($filters, Level::DISTRICT, $parent);
    }

    /**
     * Mapa por niveles: departamentos del país, provincias de un departamento o distritos
     * de una provincia/departamento, coloreables por la métrica elegida.
     */
    public function regionsFeatureCollection(StatsFilters $filters, string $level, ?string $parent = null): array
    {
        $key = "map:{$level}:".($parent ?? 'all').':'.$filters->cacheKey();

        return $this->cache->remember($key, function () use ($filters, $level, $parent) {
            $ranking = $this->stats->rankings($filters, $level, $parent);
            $codes = array_column($ranking['items'], 'code');

            [$table, $column, $geom] = match ($level) {
                Level::DEPARTMENT => ['departments', 'code', 'geom_simplified'],
                Level::PROVINCE => ['provinces', 'code', 'geom_simplified'],
                default => ['districts', 'ubigeo', 'COALESCE(geom_simplified, geom)'],
            };

            $geometries = DB::table($table)
                ->whereIn($column, $codes)
                ->selectRaw("{$column} AS code, ST_AsGeoJSON({$geom}, 5) AS geometry")
                ->pluck('geometry', 'code');

            $features = [];
            foreach ($ranking['items'] as $item) {
                if (! isset($geometries[$item['code']])) {
                    continue; // sin contorno: no se dibuja (sigue en el ranking)
                }
                $properties = [
                    'code' => $item['code'],
                    'level' => $level,
                    'name' => $item['name'],
                    'population' => $item['population'],
                    'total' => $item['total'],
                    'rate' => $item['rate'],
                    'previous_total' => $item['previous_total'],
                    'change_pct' => $item['change_pct'],
                    'value' => $item['value'],
                    'rank' => $item['rank'],
                ];
                if ($level === Level::DISTRICT) {
                    $properties += ['ubigeo' => $item['ubigeo'], 'province' => $item['province']];
                }

                $features[] = [
                    'type' => 'Feature',
                    'id' => $item['code'],
                    'geometry' => json_decode((string) $geometries[$item['code']], true),
                    'properties' => $properties,
                ];
            }

            $values = array_filter(array_column($ranking['items'], 'value'), fn ($v) => $v !== null);

            return [
                'type' => 'FeatureCollection',
                'features' => $features,
                'properties' => $this->stats->meta($filters, $ranking['metric']) + [
                    'level' => $level,
                    'child_level' => Level::childOf($level),
                    'parent' => $parent ? $this->stats->regionRef($parent) : null,
                    'breadcrumb' => $this->stats->breadcrumb($parent),
                    'count' => count($features),
                    'min_value' => $values ? min($values) : null,
                    'max_value' => $values ? max($values) : null,
                ],
            ];
        });
    }
}
