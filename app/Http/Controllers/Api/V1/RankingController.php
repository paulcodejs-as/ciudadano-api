<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegionFilterRequest;
use App\Http\Resources\RankingItemResource;
use App\Services\CrimeStatsService;
use App\Support\ApiResponse;
use App\Support\Level;
use Illuminate\Http\JsonResponse;

class RankingController extends Controller
{
    use ApiResponse;

    /** /rankings?level=province&parent=15 — sin nivel ni padre: todos los distritos del ámbito. */
    public function index(RegionFilterRequest $request, CrimeStatsService $stats): JsonResponse
    {
        $filters = $stats->resolveFilters($request->statsInput());
        $level = $request->level(Level::DISTRICT);
        $parent = $request->parent();
        $ranking = $stats->rankings($filters, $level, $parent);

        return $this->success(
            RankingItemResource::collection($ranking['items'])->resolve(),
            'Ranking por denuncias registradas.',
            $stats->meta($filters, $ranking['metric']) + [
                'count' => count($ranking['items']),
                'level' => $level,
                'parent' => $parent ? $stats->regionRef($parent) : null,
            ],
        );
    }
}
