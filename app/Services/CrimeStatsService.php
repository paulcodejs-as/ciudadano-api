<?php

namespace App\Services;

use App\Models\CrimeType;
use App\Models\DataSource;
use App\Models\District;
use App\Support\StatsFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Totales, tasas, rankings y series de denuncias registradas.
 * Toda la agregación se hace en SQL; en PHP solo se ordena el resultado
 * (unas decenas de distritos).
 */
class CrimeStatsService
{
    private ?int $sourceId = null;

    public function __construct(private readonly StatsCache $cache)
    {
    }

    /**
     * Convierte los filtros validados (year, from, to, crime_type, metric) en un rango concreto.
     * Sin rango, usa los últimos N meses con datos.
     */
    public function resolveFilters(array $input): StatsFilters
    {
        $months = max(1, (int) config('ciudadano.default_months', 12));
        $latest = $this->latestPeriod() ?? Carbon::now()->startOfMonth();

        if (! empty($input['year'])) {
            $from = Carbon::create((int) $input['year'], 1, 1);
            $to = Carbon::create((int) $input['year'], 12, 1);
            // Año en curso: hasta el último mes con datos.
            if ($latest->year === (int) $input['year']) {
                $to = $latest->copy();
            }
        } else {
            $to = ! empty($input['to']) ? Carbon::createFromFormat('!Y-m', $input['to']) : null;
            $from = ! empty($input['from']) ? Carbon::createFromFormat('!Y-m', $input['from']) : null;

            $to ??= $from && $from->greaterThan($latest) ? $from->copy() : $latest->copy();

            if ($from === null) {
                $from = $to->copy()->subMonths($months - 1);
                // Sin rango explícito no se muestran meses previos a los datos (saldrían en 0).
                $earliest = $this->earliestPeriod();
                if ($earliest && $earliest->greaterThan($from) && $earliest->lessThanOrEqualTo($to)) {
                    $from = $earliest;
                }
            }
        }

        $slug = $input['crime_type'] ?? null;
        $crimeTypeId = $slug ? CrimeType::where('slug', $slug)->value('id') : null;

        return new StatsFilters(
            $from->year, $from->month, $to->year, $to->month,
            $crimeTypeId, $crimeTypeId ? $slug : null,
            $input['metric'] ?? StatsFilters::METRIC_RATE,
        );
    }

    /** Último mes con datos importados, o null si no hay datos. */
    public function latestPeriod(): ?Carbon
    {
        $key = $this->cache->remember('latest-period', fn () => (int) DB::table('crime_stats_monthly')
            ->where('data_source_id', $this->sourceId())
            ->selectRaw('MAX(year * 100 + month) AS k')
            ->value('k'));

        return $key > 0 ? Carbon::create(intdiv($key, 100), $key % 100, 1) : null;
    }

    /** Primer mes con datos importados, o null si no hay datos. */
    public function earliestPeriod(): ?Carbon
    {
        $key = $this->cache->remember('earliest-period', fn () => (int) DB::table('crime_stats_monthly')
            ->where('data_source_id', $this->sourceId())
            ->selectRaw('MIN(year * 100 + month) AS k')
            ->value('k'));

        return $key > 0 ? Carbon::create(intdiv($key, 100), $key % 100, 1) : null;
    }

    /**
     * Ranking de todos los distritos del ámbito (los que no tienen denuncias salen con 0).
     * rank 1 = más denuncias (o mayor tasa). Si ningún distrito tiene población,
     * la métrica efectiva pasa a "total".
     *
     * @return array{metric:string, items:list<array<string, mixed>>}
     */
    public function rankings(StatsFilters $filters): array
    {
        return $this->cache->remember('rankings:'.$filters->cacheKey(), function () use ($filters) {
            $totals = $this->baseQuery($filters)
                ->selectRaw('district_id, SUM(count) AS total')
                ->groupBy('district_id');

            $rows = District::query()
                ->limaCallao()
                ->leftJoinSub($totals, 's', 's.district_id', '=', 'districts.id')
                ->select(['districts.id', 'districts.ubigeo', 'districts.name', 'districts.province', 'districts.population'])
                ->selectRaw('COALESCE(s.total, 0) AS total')
                ->toBase()
                ->get();

            $hasPopulation = $rows->contains(fn ($r) => (int) $r->population > 0);
            $metric = $filters->metric === StatsFilters::METRIC_RATE && $hasPopulation
                ? StatsFilters::METRIC_RATE
                : StatsFilters::METRIC_TOTAL;

            $items = $rows->map(function ($r) use ($metric) {
                $total = (int) $r->total;
                $population = $r->population !== null ? (int) $r->population : null;
                $rate = $this->rate($total, $population);

                return [
                    'district_id' => (int) $r->id,
                    'ubigeo' => $r->ubigeo,
                    'name' => $r->name,
                    'province' => $r->province,
                    'population' => $population,
                    'total' => $total,
                    'rate' => $rate,
                    'value' => $metric === StatsFilters::METRIC_RATE ? $rate : $total,
                ];
            })->all();

            return ['metric' => $metric, 'items' => $this->rank($items)];
        });
    }

    /**
     * Detalle de un distrito: totales por modalidad (siempre las 7, sin aplicar
     * el filtro de modalidad), serie mensual y posición en el ranking.
     */
    public function districtDetail(District $district, StatsFilters $filters): array
    {
        return $this->cache->remember("district:{$district->ubigeo}:".$filters->cacheKey(), function () use ($district, $filters) {
            $ranking = $this->rankings($filters);
            $position = collect($ranking['items'])->firstWhere('district_id', $district->id);

            // Por modalidad: ignora el filtro crime_type para poder comparar.
            $allTypes = $filters->crimeTypeId ? new StatsFilters(
                $filters->fromYear, $filters->fromMonth, $filters->toYear, $filters->toMonth, null, null, $filters->metric
            ) : $filters;

            $byType = $this->baseQuery($allTypes)
                ->where('district_id', $district->id)
                ->selectRaw('crime_type_id, SUM(count) AS total')
                ->groupBy('crime_type_id')
                ->pluck('total', 'crime_type_id');

            $crimeTypes = CrimeType::orderBy('name')->get()->map(fn (CrimeType $t) => [
                'slug' => $t->slug,
                'name' => $t->name,
                'color' => $t->color,
                'total' => (int) ($byType[$t->id] ?? 0),
                'rate' => $this->rate((int) ($byType[$t->id] ?? 0), $district->population),
            ])->sortByDesc('total')->values()->all();

            $series = $this->baseQuery($filters)
                ->where('district_id', $district->id)
                ->selectRaw('year, month, SUM(count) AS total')
                ->groupBy('year', 'month')
                ->get()
                ->mapWithKeys(fn ($r) => [sprintf('%04d-%02d', $r->year, $r->month) => (int) $r->total]);

            return [
                'total' => $position['total'] ?? 0,
                'rate' => $position['rate'] ?? null,
                'ranking' => [
                    'position' => $position['rank'] ?? null,
                    'of' => count($ranking['items']),
                    'metric' => $ranking['metric'],
                ],
                'by_crime_type' => $crimeTypes,
                'monthly' => $this->fillMonths($filters, $series->all()),
            ];
        });
    }

    /** Metadatos comunes: rango efectivo, filtros y avisos para la UI. */
    public function meta(StatsFilters $filters, string $effectiveMetric): array
    {
        $notices = ['Son denuncias registradas, no delitos ocurridos: hay subregistro.'];

        $cutoff = (int) str_replace('-', '', (string) config('ciudadano.virtual_reports_cutoff', '2024-11'));
        if ($filters->toKey() >= $cutoff && in_array($filters->crimeTypeSlug, [null, 'robo', 'hurto'], true)) {
            $notices[] = 'Desde noviembre de 2024 SIDPOL no incluye las denuncias virtuales de robo y hurto: '
                .'la serie tiene un salto y no es comparable con los meses anteriores.';
        }

        if ($filters->metric !== $effectiveMetric) {
            $notices[] = 'No hay datos de población: se muestra el total de denuncias en lugar de la tasa por 10 000 habitantes.';
        }

        return [
            'from' => $filters->from(),
            'to' => $filters->to(),
            'crime_type' => $filters->crimeTypeSlug,
            'metric' => $effectiveMetric,
            'metric_requested' => $filters->metric,
            'latest_period' => $this->latestPeriod()?->format('Y-m'),
            'population_source' => $effectiveMetric === StatsFilters::METRIC_RATE ? config('ciudadano.population_source') : null,
            'notices' => $notices,
        ];
    }

    /** Denuncias por 10 000 habitantes, o null sin población. */
    public function rate(int $total, ?int $population): ?float
    {
        return $population ? round($total / $population * 10000, 2) : null;
    }

    private function baseQuery(StatsFilters $filters): Builder
    {
        return DB::table('crime_stats_monthly')
            ->where('data_source_id', $this->sourceId())
            ->whereRaw('(year * 100 + month) BETWEEN ? AND ?', [$filters->fromKey(), $filters->toKey()])
            ->when($filters->crimeTypeId, fn (Builder $q, int $id) => $q->where('crime_type_id', $id));
    }

    /**
     * Ordena de mayor a menor valor (nulos al final) y asigna posiciones;
     * los empates comparten posición (1, 2, 2, 4).
     */
    private function rank(array $items): array
    {
        usort($items, fn ($a, $b) => [$a['value'] === null, $b['value'] ?? 0, $b['total'], $a['name']]
            <=> [$b['value'] === null, $a['value'] ?? 0, $a['total'], $b['name']]);

        $rank = 0;
        $previous = null;
        foreach ($items as $i => &$item) {
            if ($item['value'] === null) {
                $item['rank'] = null;

                continue;
            }
            if ($item['value'] !== $previous) {
                $rank = $i + 1;
                $previous = $item['value'];
            }
            $item['rank'] = $rank;
        }

        return $items;
    }

    /** Serie continua mes a mes (los meses sin filas valen 0). */
    private function fillMonths(StatsFilters $filters, array $series): array
    {
        $out = [];
        $cursor = Carbon::create($filters->fromYear, $filters->fromMonth, 1);
        $end = Carbon::create($filters->toYear, $filters->toMonth, 1);

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $out[] = ['period' => $key, 'total' => $series[$key] ?? 0];
            $cursor->addMonth();
        }

        return $out;
    }

    private function sourceId(): int
    {
        return $this->sourceId ??= (int) DataSource::where('slug', DataSource::SIDPOL)->value('id');
    }
}
