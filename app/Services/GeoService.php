<?php

namespace App\Services;

use App\Models\District;
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
            ->limaCallao()
            ->whereRaw(
                "ST_Contains(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
                [sprintf('POINT(%.7F %.7F)', $lng, $lat)]
            )
            ->first();
    }

    /**
     * FeatureCollection con las geometrías simplificadas y los totales del rango.
     * Los metadatos van en "properties" de la colección.
     */
    public function districtsFeatureCollection(StatsFilters $filters): array
    {
        return $this->cache->remember('map:'.$filters->cacheKey(), function () use ($filters) {
            $ranking = $this->stats->rankings($filters);

            $geometries = DB::table('districts')
                ->whereIn('id', array_column($ranking['items'], 'district_id'))
                ->selectRaw('id, ST_AsGeoJSON(COALESCE(geom_simplified, geom), 6) AS geometry')
                ->pluck('geometry', 'id');

            $features = [];
            foreach ($ranking['items'] as $item) {
                $features[] = [
                    'type' => 'Feature',
                    'id' => $item['ubigeo'],
                    'geometry' => json_decode((string) $geometries[$item['district_id']], true),
                    'properties' => [
                        'ubigeo' => $item['ubigeo'],
                        'name' => $item['name'],
                        'province' => $item['province'],
                        'population' => $item['population'],
                        'total' => $item['total'],
                        'rate' => $item['rate'],
                        'value' => $item['value'],
                        'rank' => $item['rank'],
                    ],
                ];
            }

            $values = array_filter(array_column($ranking['items'], 'value'), fn ($v) => $v !== null);

            return [
                'type' => 'FeatureCollection',
                'features' => $features,
                'properties' => $this->stats->meta($filters, $ranking['metric']) + [
                    'count' => count($features),
                    'min_value' => $values ? min($values) : null,
                    'max_value' => $values ? max($values) : null,
                ],
            ];
        });
    }
}
