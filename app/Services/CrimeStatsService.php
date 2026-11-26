<?php

namespace App\Services;

use App\Models\CrimeType;
use App\Models\DataSource;
use App\Models\District;
use App\Support\Level;
use App\Support\StatsFilters;
use App\Support\UbigeoScope;
use Closure;
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
     * Ranking de las regiones de un nivel (departamentos, provincias o distritos), opcionalmente
     * dentro de un "padre" (código de departamento o provincia). Las que no tienen denuncias salen
     * con 0. rank 1 = más denuncias (o mayor tasa / mayor alza). Si ninguna tiene población, la
     * métrica efectiva pasa a "total".
     *
     * @return array{metric:string, level:string, parent:?string, items:list<array<string, mixed>>}
     */
    public function rankings(StatsFilters $filters, string $level = Level::DISTRICT, ?string $parent = null): array
    {
        $key = "rankings:{$level}:".($parent ?? 'all').':'.$filters->cacheKey();

        return $this->cache->remember($key, function () use ($filters, $level, $parent) {
            $len = Level::length($level);
            $sum = fn (StatsFilters $f) => $this->scopedDistricts(
                $this->baseQuery($f)->join('districts as sd', 'sd.id', '=', 'crime_stats_monthly.district_id'),
                'sd.ubigeo',
                $parent
            )
                ->selectRaw("LEFT(sd.ubigeo, {$len}) AS code, SUM(crime_stats_monthly.count) AS total")
                ->groupByRaw("LEFT(sd.ubigeo, {$len})");

            $rows = $this->regionQuery($level, $parent)
                ->leftJoinSub($sum($filters), 's', 's.code', '=', $level === Level::DISTRICT ? 'r.ubigeo' : 'r.code')
                ->leftJoinSub($sum($filters->previousYear()), 'p', 'p.code', '=', $level === Level::DISTRICT ? 'r.ubigeo' : 'r.code')
                ->selectRaw('COALESCE(s.total, 0) AS total, COALESCE(p.total, 0) AS previous_total')
                ->get();

            $comparable = $this->comparisonAvailable($filters);
            $hasPopulation = $rows->contains(fn ($r) => (int) $r->population > 0);
            $metric = match (true) {
                $filters->metric === StatsFilters::METRIC_CHANGE => StatsFilters::METRIC_CHANGE,
                $filters->metric === StatsFilters::METRIC_RATE && $hasPopulation => StatsFilters::METRIC_RATE,
                default => StatsFilters::METRIC_TOTAL,
            };

            $items = $rows->map(function ($r) use ($metric, $comparable, $level) {
                $total = (int) $r->total;
                $previous = $comparable ? (int) $r->previous_total : null;
                $population = $r->population !== null ? (int) $r->population : null;
                $rate = $this->rate($total, $population);
                $change = $this->changePct($total, $previous);

                $item = [
                    'code' => $r->code,
                    'level' => $level,
                    'name' => $r->name,
                    'population' => $population,
                    'total' => $total,
                    'rate' => $rate,
                    'previous_total' => $previous,
                    'change_pct' => $change,
                    'value' => match ($metric) {
                        StatsFilters::METRIC_RATE => $rate,
                        StatsFilters::METRIC_CHANGE => $change,
                        default => $total,
                    },
                ];

                if ($level === Level::DISTRICT) {
                    $item += ['district_id' => (int) $r->id, 'ubigeo' => $r->code, 'province' => $r->province, 'department' => $r->department];
                }

                return $item;
            })->all();

            return ['metric' => $metric, 'level' => $level, 'parent' => $parent, 'items' => $this->rank($items)];
        });
    }

    /** Filas base (código, nombre, población) de un nivel, dentro del padre y del ámbito. */
    /**
     * Valor de cada región mes a mes (para animar el mapa): una sola consulta agrupada por
     * región y mes. "change" compara cada mes con el mismo mes del año anterior.
     *
     * @return array{metric: string, level: string, parent: ?string, periods: list<string>, items: list<array>, min: ?float, max: ?float}
     */
    public function timeline(StatsFilters $filters, string $level, ?string $parent = null): array
    {
        $key = "timeline:{$level}:".($parent ?? 'all').':'.$filters->cacheKey();

        return $this->cache->remember($key, function () use ($filters, $level, $parent) {
            $len = Level::length($level);
            $comparable = $this->comparisonAvailable($filters);
            $periods = array_column($this->fillMonths($filters, []), 'period');

            $monthly = function (StatsFilters $f, int $shiftYears) use ($len, $parent): array {
                $rows = $this->scopedDistricts(
                    $this->baseQuery($f)->join('districts as sd', 'sd.id', '=', 'crime_stats_monthly.district_id'),
                    'sd.ubigeo',
                    $parent
                )
                    ->selectRaw("LEFT(sd.ubigeo, {$len}) AS code, crime_stats_monthly.year AS y, crime_stats_monthly.month AS m, SUM(crime_stats_monthly.count) AS total")
                    ->groupByRaw("LEFT(sd.ubigeo, {$len}), crime_stats_monthly.year, crime_stats_monthly.month")
                    ->get();

                $out = [];
                foreach ($rows as $r) {
                    $out[$r->code][sprintf('%04d-%02d', $r->y + $shiftYears, $r->m)] = (int) $r->total;
                }

                return $out;
            };

            $current = $monthly($filters, 0);
            $previous = $comparable ? $monthly($filters->previousYear(), 1) : [];
            $regions = $this->regionQuery($level, $parent)->get();

            $hasPopulation = $regions->contains(fn ($r) => (int) $r->population > 0);
            $metric = match (true) {
                $filters->metric === StatsFilters::METRIC_CHANGE => StatsFilters::METRIC_CHANGE,
                $filters->metric === StatsFilters::METRIC_RATE && $hasPopulation => StatsFilters::METRIC_RATE,
                default => StatsFilters::METRIC_TOTAL,
            };

            $all = [];
            $items = $regions->map(function ($r) use ($periods, $current, $previous, $comparable, $metric, &$all) {
                $population = $r->population !== null ? (int) $r->population : null;
                $totals = [];
                $values = [];
                foreach ($periods as $period) {
                    $total = $current[$r->code][$period] ?? 0;
                    $totals[] = $total;
                    $values[] = match ($metric) {
                        StatsFilters::METRIC_RATE => $this->rate($total, $population),
                        StatsFilters::METRIC_CHANGE => $comparable ? $this->changePct($total, $previous[$r->code][$period] ?? null) : null,
                        default => $total,
                    };
                }
                array_push($all, ...array_filter($values, fn ($v) => $v !== null));

                return [
                    'code' => $r->code,
                    'name' => $r->name,
                    'population' => $population,
                    'totals' => $totals,
                    'values' => $values,
                ];
            })->sortBy('name')->values()->all();

            return [
                'metric' => $metric,
                'level' => $level,
                'parent' => $parent,
                'periods' => $periods,
                'items' => $items,
                'min' => $all ? min($all) : null,
                'max' => $all ? max($all) : null,
            ];
        });
    }

    private function regionQuery(string $level, ?string $parent): Builder
    {
        return match ($level) {
            Level::DEPARTMENT => $this->scopedRegions(DB::table('departments as r'), 'r.code')
                ->select(['r.code', 'r.name', 'r.population']),
            Level::PROVINCE => $this->scopedRegions(DB::table('provinces as r'), 'r.code')
                ->when($parent, fn (Builder $q) => $q->where('r.code', 'like', $parent.'%'))
                ->select(['r.code', 'r.name', 'r.population']),
            default => $this->scopedDistricts(DB::table('districts as r'), 'r.ubigeo', $parent)
                ->select(['r.id', 'r.ubigeo', 'r.ubigeo as code', 'r.name', 'r.population', 'r.province', 'r.department']),
        };
    }

    /** Restringe a distritos del ámbito configurado y (opcional) de un padre. */
    private function scopedDistricts(Builder $q, string $column, ?string $parent = null): Builder
    {
        $prefixes = UbigeoScope::prefixes();

        return $q
            ->when($parent, fn (Builder $q) => $q->where($column, 'like', $parent.'%'))
            ->when($prefixes, fn (Builder $q) => $q->where(function (Builder $w) use ($prefixes, $column) {
                foreach ($prefixes as $prefix) {
                    $w->orWhere($column, 'like', $prefix.'%');
                }
            }));
    }

    /** Departamentos/provincias que se cruzan con el ámbito (todo, si es nacional). */
    private function scopedRegions(Builder $q, string $column): Builder
    {
        $prefixes = UbigeoScope::prefixes();

        return $q->when($prefixes, fn (Builder $q) => $q->where(function (Builder $w) use ($prefixes, $column) {
            foreach ($prefixes as $prefix) {
                $w->orWhere($column, 'like', $prefix.'%')->orWhereRaw('? LIKE CONCAT('.$column.", '%')", [$prefix]);
            }
        }));
    }

    /**
     * Varios distritos lado a lado con el mismo filtro (para comparar).
     *
     * @param  iterable<District>  $districts
     * @return list<array<string, mixed>>
     */
    public function compare(iterable $districts, StatsFilters $filters): array
    {
        $out = [];
        foreach ($districts as $district) {
            $out[] = ['district' => $district] + $this->districtDetail($district, $filters);
        }

        return $out;
    }

    /** ¿Hay datos de todos los meses del mismo periodo del año anterior? */
    public function comparisonAvailable(StatsFilters $filters): bool
    {
        $earliest = $this->earliestPeriod();

        return $earliest !== null
            && $filters->previousYear()->fromKey() >= (int) $earliest->format('Ym');
    }

    /** % de cambio redondeado a 1 decimal; null si no hay base de comparación. */
    public function changePct(int $current, ?int $previous): ?float
    {
        return $previous ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    /** Detalle de un distrito (ver regionDetail). */
    public function districtDetail(District $district, StatsFilters $filters): array
    {
        return $this->regionDetail($district->ubigeo, $filters);
    }

    /**
     * Detalle de un departamento, provincia o distrito (según la longitud del código):
     * totales por modalidad (siempre las 7, sin aplicar el filtro de modalidad), serie
     * mensual, posición frente a sus hermanos (mismo padre) y contexto: promedios de sus
     * niveles superiores y del país y, para distritos, sus vecinos.
     */
    public function regionDetail(string $code, StatsFilters $filters): array
    {
        return $this->cache->remember("region:{$code}:".$filters->cacheKey(), function () use ($code, $filters) {
            $level = Level::ofCode($code);
            $parentLevel = Level::parentOf($level);
            $parent = $parentLevel ? Level::ancestor($code, $parentLevel) : null;

            $ranking = $this->rankings($filters, $level, $parent);
            $position = collect($ranking['items'])->firstWhere('code', $code);
            $population = $position['population'] ?? null;
            $comparable = $this->comparisonAvailable($filters);
            $districtIds = $this->districtIdsLike($code);

            // Por modalidad: ignora el filtro crime_type para poder comparar.
            $allTypes = $filters->withoutCrimeType();
            $byTypeOf = fn (StatsFilters $f) => $this->baseQuery($f)
                ->whereIn('district_id', $districtIds)
                ->selectRaw('crime_type_id, SUM(count) AS total')
                ->groupBy('crime_type_id')
                ->pluck('total', 'crime_type_id');
            $byType = $byTypeOf($allTypes);
            $byTypePrevious = $comparable ? $byTypeOf($allTypes->previousYear()) : collect();

            $crimeTypes = CrimeType::orderBy('name')->get()->map(function (CrimeType $t) use ($byType, $byTypePrevious, $comparable, $population) {
                $total = (int) ($byType[$t->id] ?? 0);
                $previous = $comparable ? (int) ($byTypePrevious[$t->id] ?? 0) : null;

                return [
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'color' => $t->color,
                    'total' => $total,
                    'rate' => $this->rate($total, $population),
                    'previous_total' => $previous,
                    'change_pct' => $this->changePct($total, $previous),
                ];
            })->sortByDesc('total')->values()->all();

            $seriesOf = fn (StatsFilters $f) => $this->baseQuery($f)
                ->whereIn('district_id', $districtIds)
                ->selectRaw('year, month, SUM(count) AS total')
                ->groupBy('year', 'month')
                ->get()
                ->mapWithKeys(fn ($r) => [sprintf('%04d-%02d', $r->year, $r->month) => (int) $r->total])
                ->all();

            $monthly = $this->fillMonths($filters, $seriesOf($filters));
            $previousSeries = $comparable ? $seriesOf($filters->previousYear()) : [];

            foreach ($monthly as &$point) {
                $point['rate'] = $this->rate($point['total'], $population);
                // Mismo mes del año anterior (para dibujarlo superpuesto).
                $previousKey = sprintf('%04d-%s', (int) substr($point['period'], 0, 4) - 1, substr($point['period'], 5, 2));
                $point['previous_total'] = $comparable ? ($previousSeries[$previousKey] ?? 0) : null;
            }
            unset($point);

            return [
                'region' => ['code' => $code, 'level' => $level, 'name' => $position['name'] ?? null, 'population' => $population],
                'total' => $position['total'] ?? 0,
                'rate' => $position['rate'] ?? null,
                'previous_total' => $position['previous_total'] ?? null,
                'change_pct' => $position['change_pct'] ?? null,
                'ranking' => [
                    'position' => $position['rank'] ?? null,
                    'of' => count($ranking['items']),
                    'metric' => $ranking['metric'],
                    // Frente a quién se compara: los hermanos dentro del mismo padre (o todo el país).
                    'within' => $parent ? $this->regionRef($parent) : ['code' => null, 'level' => 'country', 'name' => 'Perú'],
                ],
                'by_crime_type' => $crimeTypes,
                'monthly' => $monthly,
                'context' => [
                    'averages' => $this->averages($code, $filters),
                    'neighbors' => $level === Level::DISTRICT ? $this->neighbors($code, $filters) : [],
                ],
            ];
        });
    }

    /**
     * Totales y tasa de los niveles superiores del código y del país, con los mismos filtros
     * ("¿está por encima o por debajo del promedio de su provincia?").
     *
     * @return list<array<string, mixed>>
     */
    private function averages(string $code, StatsFilters $filters): array
    {
        $refs = [];
        foreach ([Level::PROVINCE, Level::DEPARTMENT] as $lvl) {
            if (strlen($code) > Level::length($lvl)) {
                $refs[] = $this->regionRef(Level::ancestor($code, $lvl));
            }
        }
        $refs[] = [
            'code' => null,
            'level' => 'country',
            'name' => 'Perú',
            'population' => (int) DB::table('departments')->sum('population') ?: null,
        ];

        $comparable = $this->comparisonAvailable($filters);

        return array_map(function (array $ref) use ($filters, $comparable) {
            $ids = $this->districtIdsLike($ref['code'] ?? '');
            $total = (int) $this->baseQuery($filters)->whereIn('district_id', $ids)->sum('count');
            $previous = $comparable ? (int) $this->baseQuery($filters->previousYear())->whereIn('district_id', $ids)->sum('count') : null;

            return $ref + [
                'total' => $total,
                'rate' => $this->rate($total, $ref['population']),
                'change_pct' => $this->changePct($total, $previous),
            ];
        }, $refs);
    }

    /**
     * Distritos colindantes con su tasa y cambio, de mayor a menor tasa.
     *
     * @return list<array<string, mixed>>
     */
    private function neighbors(string $ubigeo, StatsFilters $filters): array
    {
        $neighbors = collect($this->cache->remember("neighbors:{$ubigeo}", fn () => DB::select(
            'SELECT n.id, n.ubigeo, n.name, n.province, n.population FROM districts d
             JOIN districts n ON n.id <> d.id AND MBRIntersects(n.geom, d.geom) AND ST_Intersects(n.geom, d.geom)
             WHERE d.ubigeo = ?', [$ubigeo]
        )));
        if ($neighbors->isEmpty()) {
            return [];
        }

        $ids = $neighbors->pluck('id')->all();
        $sumOf = fn (StatsFilters $f) => $this->baseQuery($f)->whereIn('district_id', $ids)
            ->selectRaw('district_id, SUM(count) AS total')->groupBy('district_id')->pluck('total', 'district_id');
        $current = $sumOf($filters);
        $comparable = $this->comparisonAvailable($filters);
        $previous = $comparable ? $sumOf($filters->previousYear()) : collect();

        return $neighbors->map(function ($n) use ($current, $previous, $comparable) {
            $total = (int) ($current[$n->id] ?? 0);
            $population = $n->population !== null ? (int) $n->population : null;

            return [
                'ubigeo' => $n->ubigeo,
                'name' => $n->name,
                'province' => $n->province,
                'population' => $population,
                'total' => $total,
                'rate' => $this->rate($total, $population),
                'change_pct' => $this->changePct($total, $comparable ? (int) ($previous[$n->id] ?? 0) : null),
            ];
        })->sortByDesc(fn ($n) => $n['rate'] ?? -1)->values()->all();
    }

    /** Código, nivel, nombre y población de un departamento, provincia o distrito. */
    public function regionRef(string $code): array
    {
        return $this->cache->remember("region-ref:{$code}", function () use ($code) {
            $level = Level::ofCode($code);
            $row = match ($level) {
                Level::DEPARTMENT => DB::table('departments')->where('code', $code)->first(['name', 'population']),
                Level::PROVINCE => DB::table('provinces')->where('code', $code)->first(['name', 'population']),
                default => DB::table('districts')->where('ubigeo', $code)->first(['name', 'population']),
            };

            return [
                'code' => $code,
                'level' => $level,
                'name' => $row?->name,
                'population' => $row?->population !== null ? (int) $row->population : null,
            ];
        });
    }

    /** Ruta de un código: Perú › departamento › provincia › distrito. */
    public function breadcrumb(?string $code): array
    {
        $crumbs = [['code' => null, 'level' => 'country', 'name' => 'Perú']];
        foreach (Level::ALL as $lvl) {
            if ($code !== null && strlen($code) >= Level::length($lvl)) {
                $ref = $this->regionRef(Level::ancestor($code, $lvl));
                $crumbs[] = ['code' => $ref['code'], 'level' => $lvl, 'name' => $ref['name']];
            }
        }

        return $crumbs;
    }

    /** Subconsulta de ids de distritos cuyo ubigeo empieza por el prefijo ('' = todos), en el ámbito. */
    private function districtIdsLike(string $prefix): Closure
    {
        return fn (Builder $q) => $this->scopedDistricts($q->from('districts')->select('id'), 'ubigeo', $prefix !== '' ? $prefix : null);
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

        $previous = $filters->previousYear();
        $comparable = $this->comparisonAvailable($filters);

        if (! $comparable && $effectiveMetric === StatsFilters::METRIC_CHANGE) {
            $notices[] = 'No hay datos del mismo periodo del año anterior: no se puede calcular el cambio.';
        }

        // Un periodo antes del corte y otro después: robo y hurto bajan por el cambio de criterio, no por menos delitos.
        if ($comparable && $previous->fromKey() < $cutoff && $filters->toKey() >= $cutoff
            && in_array($filters->crimeTypeSlug, [null, 'robo', 'hurto'], true)) {
            $notices[] = 'El cambio frente al año anterior está distorsionado: uno de los dos periodos es anterior a noviembre de 2024, '
                .'cuando SIDPOL dejó de contar las denuncias virtuales de robo y hurto. Esas modalidades bajan por el cambio de criterio.';
        }

        return [
            'from' => $filters->from(),
            'to' => $filters->to(),
            'crime_type' => $filters->crimeTypeSlug,
            'metric' => $effectiveMetric,
            'metric_requested' => $filters->metric,
            'latest_period' => $this->latestPeriod()?->format('Y-m'),
            'population_source' => $effectiveMetric === StatsFilters::METRIC_RATE ? config('ciudadano.population_source') : null,
            'comparison' => [
                'available' => $comparable,
                'from' => $previous->from(),
                'to' => $previous->to(),
            ],
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
