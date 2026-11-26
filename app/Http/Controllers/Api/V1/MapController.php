<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegionFilterRequest;
use App\Services\CrimeStatsService;
use App\Services\GeoService;
use App\Support\ApiResponse;
use App\Support\Level;
use Illuminate\Http\JsonResponse;

/**
 * Datos del mapa. /map/regions y /map/districts devuelven un GeoJSON FeatureCollection
 * (metadatos en "properties"); /map/points y /map/timeline usan el formato {success, data, meta}.
 */
class MapController extends Controller
{
    use ApiResponse;

    /** /map/regions?level=department|province|district&parent=15 */
    public function regions(RegionFilterRequest $request, CrimeStatsService $stats, GeoService $geo): JsonResponse
    {
        return $this->geojson($geo->regionsFeatureCollection(
            $stats->resolveFilters($request->statsInput()),
            $request->level(),
            $request->parent(),
        ));
    }

    /** /map/districts?parent=1501 (distritos; sin padre, todos los del ámbito). */
    public function districts(RegionFilterRequest $request, CrimeStatsService $stats, GeoService $geo): JsonResponse
    {
        return $this->geojson($geo->regionsFeatureCollection(
            $stats->resolveFilters($request->statsInput()),
            Level::DISTRICT,
            $request->parent(),
        ));
    }

    /** /map/points?level=district&parent=15: centroide + valores (mapa de calor y burbujas). */
    public function points(RegionFilterRequest $request, CrimeStatsService $stats, GeoService $geo): JsonResponse
    {
        $filters = $stats->resolveFilters($request->statsInput());
        $level = $request->level();
        $points = $geo->points($filters, $level, $request->parent());

        return $this->success($points['items'], 'Puntos del mapa.', $stats->meta($filters, $points['metric']) + [
            'level' => $level,
            'parent' => $request->parent(),
            'count' => count($points['items']),
        ]);
    }

    /** /map/timeline?parent=15: valor de cada región mes a mes (animación del mapa). */
    public function timeline(RegionFilterRequest $request, CrimeStatsService $stats): JsonResponse
    {
        $filters = $stats->resolveFilters($request->statsInput());
        $timeline = $stats->timeline($filters, $request->level(), $request->parent());

        return $this->success(
            ['periods' => $timeline['periods'], 'items' => $timeline['items']],
            'Evolución mensual del mapa.',
            $stats->meta($filters, $timeline['metric']) + [
                'level' => $timeline['level'],
                'parent' => $timeline['parent'],
                'count' => count($timeline['items']),
                'min_value' => $timeline['min'],
                'max_value' => $timeline['max'],
            ],
        );
    }

    private function geojson(array $collection): JsonResponse
    {
        return response()->json($collection, 200, ['Content-Type' => 'application/geo+json']);
    }
}
