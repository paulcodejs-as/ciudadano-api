<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatsFilterRequest;
use App\Services\CrimeStatsService;
use App\Services\GeoService;
use Illuminate\Http\JsonResponse;

class MapController extends Controller
{
    /** GeoJSON FeatureCollection (no usa el formato {success, data}); los metadatos van en "properties". */
    public function districts(StatsFilterRequest $request, CrimeStatsService $stats, GeoService $geo): JsonResponse
    {
        $collection = $geo->districtsFeatureCollection($stats->resolveFilters($request->validated()));

        return response()->json($collection, 200, ['Content-Type' => 'application/geo+json']);
    }
}
