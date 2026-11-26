<?php

namespace App\Services\Importers;

use App\Services\StatsCache;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Arma departamentos y provincias a partir de los distritos importados:
 * nombre, población y área (sumas) y la geometría (unión de sus distritos),
 * simplificada para dibujar el mapa por niveles. Es idempotente.
 *
 * La unión se hace en SRID 0 (cartesiano): ST_Union sobre el elipsoide es
 * mucho más lento y aquí solo se busca el contorno para dibujar.
 */
class RegionBuilder
{
    /** @var list<string> */
    private array $log = [];

    /** @return array{departments:int, provinces:int, log:list<string>} */
    public function build(?Closure $progress = null): array
    {
        $this->log = [];
        $now = now()->toDateTimeString();

        DB::statement('INSERT INTO provinces (code, department_code, name, population, area_km2, created_at, updated_at)
            SELECT province_code, department_code, MAX(province), SUM(population), SUM(area_km2), ?, ?
            FROM districts GROUP BY province_code, department_code
            ON DUPLICATE KEY UPDATE department_code = VALUES(department_code), name = VALUES(name),
                population = VALUES(population), area_km2 = VALUES(area_km2), updated_at = VALUES(updated_at)', [$now, $now]);

        DB::statement('INSERT INTO departments (code, name, population, area_km2, created_at, updated_at)
            SELECT department_code, MAX(department), SUM(population), SUM(area_km2), ?, ?
            FROM districts GROUP BY department_code
            ON DUPLICATE KEY UPDATE name = VALUES(name), population = VALUES(population),
                area_km2 = VALUES(area_km2), updated_at = VALUES(updated_at)', [$now, $now]);

        // Los distritos creados después del censo no traen pob_2024: su población está contada en el
        // distrito del que se separaron (la suma nacional cuadra con el total del país), así que la
        // suma por provincia y departamento sí es correcta.

        $provinceShapes = []; // department_code => [wkb, ...]
        $provinces = DB::table('provinces')->orderBy('code')->get(['code', 'department_code']);

        foreach ($provinces as $province) {
            $wkbs = DB::table('districts')->where('province_code', $province->code)
                ->selectRaw('ubigeo, ST_AsWKB(ST_SRID(geom, 0)) AS wkb')->pluck('wkb', 'ubigeo')->all();

            $union = $this->union($wkbs, "provincia {$province->code}");
            if ($union !== null) {
                $this->storeSimplified('provinces', $province->code, $union, (float) config('ciudadano.region_tolerance.province', 0.002));
                $provinceShapes[$province->department_code][$province->code] = $union;
            }
            $progress && $progress();
        }

        foreach ($provinceShapes as $code => $wkbs) {
            $union = $this->union($wkbs, "departamento {$code}");
            if ($union !== null) {
                $this->storeSimplified('departments', $code, $union, (float) config('ciudadano.region_tolerance.department', 0.006));
            }
        }

        app(StatsCache::class)->flush();

        return [
            'departments' => DB::table('departments')->whereNotNull('geom_simplified')->count(),
            'provinces' => DB::table('provinces')->whereNotNull('geom_simplified')->count(),
            'log' => $this->log,
        ];
    }

    /**
     * Une geometrías (WKB, SRID 0). Si una pieza es inválida se intenta repararla
     * con ST_Buffer(g, 0); si aun así falla, se omite y se anota.
     *
     * @param  array<string, string>  $wkbs
     */
    private function union(array $wkbs, string $label): ?string
    {
        $acc = null;
        foreach ($wkbs as $key => $wkb) {
            if ($acc === null) {
                $acc = $wkb;

                continue;
            }
            try {
                $acc = DB::selectOne('SELECT ST_AsWKB(ST_Union(ST_GeomFromWKB(?), ST_GeomFromWKB(?))) AS w', [$acc, $wkb])->w;
            } catch (Throwable) {
                try {
                    $acc = DB::selectOne('SELECT ST_AsWKB(ST_Union(ST_Buffer(ST_GeomFromWKB(?), 0), ST_Buffer(ST_GeomFromWKB(?), 0))) AS w', [$acc, $wkb])->w;
                } catch (Throwable $e) {
                    $this->log[] = "{$label}: no se pudo unir el distrito {$key} al contorno (geometría problemática).";
                }
            }
        }

        return $acc;
    }

    /** Simplifica, convierte a MULTIPOLYGON SRID 4326 y guarda en geom_simplified. */
    private function storeSimplified(string $table, string $code, string $wkb, float $tolerance): void
    {
        foreach ([$tolerance, $tolerance / 4, 0.0] as $tol) {
            $row = DB::selectOne(
                'SELECT ST_AsGeoJSON(g) AS json, ST_IsValid(g) AS valid FROM (SELECT '
                .($tol > 0 ? 'ST_Simplify(ST_GeomFromWKB(?), ?)' : 'ST_GeomFromWKB(?)').' AS g) t',
                $tol > 0 ? [$wkb, $tol] : [$wkb]
            );
            $geojson = $this->toMultiPolygon(json_decode((string) $row->json, true));

            if ($geojson !== null && ($row->valid || $tol === 0.0)) {
                DB::update("UPDATE {$table} SET geom_simplified = ST_GeomFromGeoJSON(?, 2, 4326) WHERE code = ?", [$geojson, $code]);

                return;
            }
        }

        $this->log[] = "{$table} {$code}: geometría vacía tras la unión.";
    }

    /** Polygon / MultiPolygon / GeometryCollection → GeoJSON MultiPolygon (descarta líneas y puntos). */
    private function toMultiPolygon(?array $g): ?string
    {
        $polygons = [];
        $collect = function (?array $g) use (&$collect, &$polygons) {
            match ($g['type'] ?? null) {
                'Polygon' => $polygons[] = $g['coordinates'],
                'MultiPolygon' => array_push($polygons, ...$g['coordinates']),
                'GeometryCollection' => array_map($collect, $g['geometries'] ?? []),
                default => null,
            };
        };
        $collect($g);

        return $polygons ? json_encode(['type' => 'MultiPolygon', 'coordinates' => $polygons]) : null;
    }
}
