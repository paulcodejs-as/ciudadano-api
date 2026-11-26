<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompareDistrictsRequest;
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
            District::withoutGeometry()->inScope()->orderBy('name')->get()
        )->resolve());

        return $this->success($districts, 'Distritos.', ['count' => count($districts)]);
    }

    public function show(StatsFilterRequest $request, string $ubigeo, CrimeStatsService $stats): JsonResponse
    {
        $district = District::withoutGeometry()->inScope()->where('ubigeo', $ubigeo)->firstOrFail();
        $filters = $stats->resolveFilters($request->validated());
        $detail = $stats->districtDetail($district, $filters);

        return $this->success(
            ['district' => DistrictResource::make($district)->resolve()] + $detail,
            "Denuncias registradas en {$district->name}.",
            $stats->meta($filters, $detail['ranking']['metric']),
        );
    }

    /** Varios distritos con el mismo filtro, en el orden pedido. */
    public function compare(CompareDistrictsRequest $request, CrimeStatsService $stats): JsonResponse
    {
        $ubigeos = $request->ubigeos();
        $districts = District::withoutGeometry()->inScope()->whereIn('ubigeo', $ubigeos)->get()->keyBy('ubigeo');

        $missing = array_values(array_diff($ubigeos, $districts->keys()->all()));
        if ($missing) {
            return $this->error('Distritos no encontrados: '.implode(', ', $missing).'.', 404);
        }
        if (count($ubigeos) < 2) {
            return $this->error('Elige al menos dos distritos distintos.', 422, ['ubigeos' => ['Elige al menos dos distritos distintos.']]);
        }

        $filters = $stats->resolveFilters($request->safe()->except('ubigeos'));
        $items = $stats->compare(array_map(fn ($u) => $districts[$u], $ubigeos), $filters);

        $data = array_map(fn (array $item) => ['district' => DistrictResource::make($item['district'])->resolve()]
            + array_diff_key($item, ['district' => true]), $items);

        return $this->success(
            $data,
            'Comparación de distritos.',
            $stats->meta($filters, $items[0]['ranking']['metric']) + ['count' => count($data)],
        );
    }

    public function locate(LocateDistrictRequest $request, GeoService $geo): JsonResponse
    {
        $district = $geo->locate((float) $request->validated('lat'), (float) $request->validated('lng'));

        if ($district === null) {
            return $this->error('El punto no está dentro de ningún distrito cargado.', 404);
        }

        return $this->success(DistrictResource::make($district)->resolve(), "El punto está en {$district->name}.");
    }
}
