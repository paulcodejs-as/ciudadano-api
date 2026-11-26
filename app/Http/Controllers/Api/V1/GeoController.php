<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatsFilterRequest;
use App\Http\Resources\RegionResource;
use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use App\Services\CrimeStatsService;
use App\Services\StatsCache;
use App\Support\ApiResponse;
use App\Support\Level;
use Illuminate\Http\JsonResponse;

/**
 * Listas para los filtros en cascada (departamento → provincia → distrito) y el detalle
 * de cualquier región por su código.
 */
class GeoController extends Controller
{
    use ApiResponse;

    public function departments(StatsCache $cache): JsonResponse
    {
        $items = $cache->remember('geo:departments', fn () => RegionResource::collection(
            Department::orderBy('name')->get(['code', 'name', 'population'])
        )->resolve());

        return $this->success($items, 'Departamentos.', ['count' => count($items)]);
    }

    public function provinces(string $department, StatsCache $cache): JsonResponse
    {
        $dept = Department::where('code', $department)->firstOrFail(['code', 'name']);
        $items = $cache->remember("geo:provinces:{$department}", fn () => RegionResource::collection(
            Province::where('department_code', $department)->orderBy('name')->get(['code', 'name', 'population'])
        )->resolve());

        return $this->success($items, "Provincias de {$dept->name}.", ['count' => count($items), 'department' => $dept->only('code', 'name')]);
    }

    public function districts(string $province, StatsCache $cache): JsonResponse
    {
        $prov = Province::where('code', $province)->firstOrFail(['code', 'name']);
        $items = $cache->remember("geo:districts:{$province}", fn () => RegionResource::collection(
            District::where('province_code', $province)->inScope()->orderBy('name')->get(['ubigeo as code', 'name', 'population'])
        )->resolve());

        return $this->success($items, "Distritos de {$prov->name}.", ['count' => count($items), 'province' => $prov->only('code', 'name')]);
    }

    /** Detalle de un departamento (2 dígitos), provincia (4) o distrito (6). */
    public function show(StatsFilterRequest $request, string $code, CrimeStatsService $stats): JsonResponse
    {
        $ref = $stats->regionRef($code);
        abort_if($ref['name'] === null, 404);

        $filters = $stats->resolveFilters($request->validated());
        $detail = $stats->regionDetail($code, $filters);

        return $this->success(
            $detail + ['breadcrumb' => $stats->breadcrumb($code)],
            'Denuncias registradas en '.$ref['name'].' ('.Level::label($ref['level']).').',
            $stats->meta($filters, $detail['ranking']['metric']),
        );
    }
}
