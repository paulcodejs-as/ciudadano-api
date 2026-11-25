<?php

namespace Tests\Feature\Api\V1;

use App\Models\District;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistrictsTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStats();
    }

    public function test_lists_districts_without_geometry(): void
    {
        // Fuera del ámbito: no debe salir.
        District::factory()->create(['ubigeo' => '040101', 'name' => 'AREQUIPA']);

        $response = $this->getJson('/api/v1/districts')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data.0.name', 'CALLAO')
            ->assertJsonStructure(['data' => [['ubigeo', 'name', 'province', 'department', 'population', 'area_km2']]]);

        $this->assertArrayNotHasKey('geom', $response->json('data.0'));
    }

    public function test_shows_district_detail(): void
    {
        $this->getJson('/api/v1/districts/150122?from=2024-01&to=2024-03')
            ->assertOk()
            ->assertJsonPath('data.district.name', 'MIRAFLORES')
            ->assertJsonPath('data.total', 60)
            ->assertJsonPath('data.rate', 6)
            ->assertJsonPath('data.ranking.position', 1)
            ->assertJsonPath('data.ranking.of', 3)
            ->assertJsonPath('data.ranking.metric', 'rate')
            ->assertJsonCount(7, 'data.by_crime_type')
            ->assertJsonPath('data.by_crime_type.0.slug', 'robo')
            ->assertJsonPath('data.by_crime_type.0.total', 50)
            ->assertJsonPath('data.monthly', [
                ['period' => '2024-01', 'total' => 50],
                ['period' => '2024-02', 'total' => 10],
                ['period' => '2024-03', 'total' => 0],
            ])
            ->assertJsonPath('meta.from', '2024-01')
            ->assertJsonPath('meta.to', '2024-03');
    }

    public function test_crime_type_filter_applies_to_total_and_series_but_not_breakdown(): void
    {
        $this->getJson('/api/v1/districts/150122?year=2024&crime_type=hurto')
            ->assertOk()
            ->assertJsonPath('data.total', 10)
            ->assertJsonPath('data.monthly.0.total', 0)
            ->assertJsonPath('data.monthly.1.total', 10)
            ->assertJsonPath('data.by_crime_type.0.total', 50)
            ->assertJsonPath('meta.crime_type', 'hurto')
            // Año en curso de los datos: hasta el último mes con datos.
            ->assertJsonPath('meta.to', '2024-02');
    }

    public function test_unknown_district_returns_404(): void
    {
        $this->getJson('/api/v1/districts/150199')->assertNotFound()->assertJsonPath('success', false);
        $this->getJson('/api/v1/districts/abc')->assertNotFound();
    }

    public function test_locates_point(): void
    {
        $this->getJson('/api/v1/districts/locate?lat=-12.121&lng=-77.03')
            ->assertOk()
            ->assertJsonPath('data.ubigeo', '150122');
    }

    public function test_locate_point_without_district_returns_404(): void
    {
        $this->getJson('/api/v1/districts/locate?lat=-12.40&lng=-76.80')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_locate_validates_bounds(): void
    {
        // Cusco.
        $this->getJson('/api/v1/districts/locate?lat=-13.53&lng=-71.97')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);

        $this->getJson('/api/v1/districts/locate')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
    }
}
