<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocateDistrictRequest;
use App\Http\Requests\StatsFilterRequest;
use App\Http\Resources\DistrictResource;
use App\Models\District;
use App\Services\CrimeStatsService;
use App\Services\GeoService;
use App\Services\StatsCache;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DistrictController extends Controller
{
    use ApiResponse;

    public function index(StatsCache $cache): JsonResponse
    {
        $districts = $cache->remember('districts:index', fn () => DistrictResource::collection(
            District::withoutGeometry()->limaCallao()->orderBy('name')->get()
        )->resolve());

        return $this->success($districts, 'Distritos de Lima Metropolitana y Callao.', ['count' => count($districts)]);
    }

    public function show(StatsFilterRequest $request, string $ubigeo, CrimeStatsService $stats): JsonResponse
    {
        $district = District::withoutGeometry()->limaCallao()->where('ubigeo', $ubigeo)->firstOrFail();
        $filters = $stats->resolveFilters($request->validated());
        $detail = $stats->districtDetail($district, $filters);

        return $this->success(
            ['district' => DistrictResource::make($district)->resolve()] + $detail,
            "Denuncias registradas en {$district->name}.",
            $stats->meta($filters, $detail['ranking']['metric']),
        );
    }

    public function locate(LocateDistrictRequest $request, GeoService $geo): JsonResponse
    {
        $district = $geo->locate((float) $request->validated('lat'), (float) $request->validated('lng'));

        if ($district === null) {
            return $this->error('El punto no está dentro de ningún distrito de Lima Metropolitana o Callao.', 404);
        }

        return $this->success(DistrictResource::make($district)->resolve(), "El punto está en {$district->name}.");
    }
}
