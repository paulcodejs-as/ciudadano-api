<?php

namespace Tests\Feature\Importers;

use App\Models\District;
use App\Services\Importers\DistrictGeoJsonImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DistrictGeoJsonImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'geo').'.geojson';
        file_put_contents($this->path, json_encode(self::featureCollection()));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_imports_only_lima_and_callao_with_padded_ubigeo(): void
    {
        $result = app(DistrictGeoJsonImporter::class)->import($this->path);

        $this->assertSame(3, $result['read']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['skipped']);

        $this->assertEqualsCanonicalizing(['150122', '070101'], District::pluck('ubigeo')->all());

        $callao = District::where('ubigeo', '070101')->first();
        $this->assertSame('CALLAO', $callao->name);
        $this->assertSame('CALLAO', $callao->department);
    }

    public function test_fills_simplified_geometry_and_area(): void
    {
        app(DistrictGeoJsonImporter::class)->import($this->path);

        $row = DB::table('districts')->where('ubigeo', '150122')
            ->selectRaw('ST_GeometryType(geom) AS t, ST_SRID(geom_simplified) AS s, area_km2')
            ->first();

        $this->assertSame('MULTIPOLYGON', strtoupper($row->t));
        $this->assertSame(4326, (int) $row->s);
        // Cuadrado de 0.02° × 0.02° en la latitud de Lima ≈ 4.8 km².
        $this->assertEqualsWithDelta(4.8, (float) $row->area_km2, 0.3);
    }

    public function test_point_lookup_works_after_import(): void
    {
        app(DistrictGeoJsonImporter::class)->import($this->path);

        $ubigeo = DB::table('districts')
            ->whereRaw("ST_Contains(geom, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))", ['POINT(-77.0300 -12.1210)'])
            ->value('ubigeo');

        $this->assertSame('150122', $ubigeo);
    }

    public function test_is_idempotent_and_keeps_population(): void
    {
        $importer = app(DistrictGeoJsonImporter::class);
        $importer->import($this->path);
        District::where('ubigeo', '150122')->update(['population' => 100_000]);

        $importer->import($this->path);

        $this->assertSame(2, District::count());
        $this->assertSame(100_000, District::where('ubigeo', '150122')->value('population'));
    }

    public function test_accepts_inei_arcgis_field_names(): void
    {
        file_put_contents($this->path, json_encode(['type' => 'FeatureCollection', 'features' => [[
            'type' => 'Feature',
            'properties' => ['ubigeo' => '150137', 'distrito' => 'SANTA ANITA', 'provincia' => 'LIMA', 'departamen' => 'LIMA', 'pob_2024' => 1],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [self::ring(-76.97, -12.045, 0.01)]],
        ]]]));

        $result = app(DistrictGeoJsonImporter::class)->import($this->path);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('SANTA ANITA', District::where('ubigeo', '150137')->value('name'));
    }

    public function test_command_reports_missing_file(): void
    {
        $this->artisan('ciudadano:import-districts', ['path' => 'no/existe.geojson'])
            ->assertFailed();
    }

    public function test_command_imports_file(): void
    {
        $this->artisan('ciudadano:import-districts', ['path' => $this->path])
            ->assertSuccessful();

        $this->assertSame(2, District::count());
    }

    private static function featureCollection(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                // Miraflores como Polygon.
                self::feature('150122', 'Miraflores', 'LIMA', 'LIMA', 'Polygon', [self::ring(-77.03, -12.121, 0.01)]),
                // Callao como MultiPolygon y con el 0 inicial perdido.
                self::feature(70101, 'CALLAO', 'CALLAO', 'CALLAO', 'MultiPolygon', [[self::ring(-77.13, -12.05, 0.01)]]),
                // Arequipa: fuera del ámbito.
                self::feature('040101', 'AREQUIPA', 'AREQUIPA', 'AREQUIPA', 'Polygon', [self::ring(-71.53, -16.40, 0.01)]),
            ],
        ];
    }

    private static function feature(string|int $id, string $dist, string $prov, string $dep, string $type, array $coords): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['IDDIST' => $id, 'NOMBDIST' => $dist, 'NOMBPROV' => $prov, 'NOMBDEP' => $dep],
            'geometry' => ['type' => $type, 'coordinates' => $coords],
        ];
    }

    /** Anillo cuadrado en orden antihorario (lng, lat). */
    private static function ring(float $lng, float $lat, float $half): array
    {
        return [
            [$lng - $half, $lat - $half],
            [$lng + $half, $lat - $half],
            [$lng + $half, $lat + $half],
            [$lng - $half, $lat + $half],
            [$lng - $half, $lat - $half],
        ];
    }
}
