<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatsFilterRequest;
use App\Http\Resources\RankingItemResource;
use App\Services\CrimeStatsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RankingController extends Controller
{
    use ApiResponse;

    public function index(StatsFilterRequest $request, CrimeStatsService $stats): JsonResponse
    {
        $filters = $stats->resolveFilters($request->validated());
        $ranking = $stats->rankings($filters);

        return $this->success(
            RankingItemResource::collection($ranking['items'])->resolve(),
            'Ranking de distritos por denuncias registradas.',
            $stats->meta($filters, $ranking['metric']) + ['count' => count($ranking['items'])],
        );
    }
}
