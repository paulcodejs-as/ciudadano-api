<?php

namespace Tests\Feature;

use App\Models\District;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Comprueba que la base de datos soporta lo espacial que usará Ciudadano
 * (MySQL 8 con SRID 4326).
 */
class SpatialSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_8_with_spatial_support(): void
    {
        $version = DB::selectOne('SELECT VERSION() AS v')->v;
        $this->assertTrue(version_compare($version, '8.0.0', '>='), "Se requiere MySQL 8+, hay {$version}");

        $srs = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.ST_SPATIAL_REFERENCE_SYSTEMS WHERE SRS_ID = 4326');
        $this->assertSame(1, (int) $srs->n);
    }

    public function test_point_in_district_with_st_contains(): void
    {
        // Cuadrado alrededor del parque Kennedy (Miraflores).
        $district = District::factory()->squareAround(-77.0300, -12.1210, 0.02)->create();

        $inside = $this->containing(-77.0290, -12.1200);
        $outside = $this->containing(-76.5000, -11.5000);

        $this->assertSame($district->id, $inside);
        $this->assertNull($outside);
    }

    public function test_geojson_keeps_lng_lat_order(): void
    {
        $district = District::factory()->squareAround(-77.03, -12.12, 0.01)->create();

        $json = DB::table('districts')->where('id', $district->id)
            ->selectRaw('ST_AsGeoJSON(geom) AS g')->value('g');
        $first = json_decode($json, true)['coordinates'][0][0][0];

        $this->assertEqualsWithDelta(-77.04, $first[0], 0.0001); // lng
        $this->assertEqualsWithDelta(-12.13, $first[1], 0.0001); // lat
    }

    public function test_geometry_can_be_simplified(): void
    {
        $district = District::factory()->create();

        // ST_Simplify no acepta SRS geográficos: se simplifica en SRID 0 y se vuelve a 4326.
        DB::statement('UPDATE districts
            SET geom_simplified = ST_SRID(ST_Simplify(ST_SRID(geom, 0), 0.0005), 4326)
            WHERE id = ?', [$district->id]);

        $type = DB::table('districts')->where('id', $district->id)
            ->selectRaw('ST_GeometryType(geom_simplified) AS t, ST_SRID(geom_simplified) AS s')->first();

        $this->assertSame(4326, (int) $type->s);
        $this->assertContains(strtoupper($type->t), ['MULTIPOLYGON', 'POLYGON']);
    }

    private function containing(float $lng, float $lat): ?int
    {
        $id = DB::table('districts')
            ->whereRaw("ST_Contains(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))", ["POINT({$lng} {$lat})"])
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
