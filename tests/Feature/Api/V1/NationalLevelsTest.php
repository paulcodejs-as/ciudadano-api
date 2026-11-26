<?php

namespace Tests\Feature\Api\V1;

use App\Models\District;
use App\Services\Importers\RegionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alcance nacional: listas en cascada, mapa y ranking por niveles, detalle de cualquier
 * región y contexto del distrito.
 *
 * Datos: StatsTestData (Lima: Miraflores 60 denuncias/100 000 hab., Comas sin datos; Callao: 100/400 000)
 * + Arequipa (040101, 50 000 hab., 30 denuncias) para tener 3 departamentos.
 * Miraflores y Barranco (150104) son vecinos (cuadrados que se tocan).
 */
class NationalLevelsTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStats();

        $arequipa = District::factory()->squareAround(-71.53, -16.40, 0.02)->create([
            'ubigeo' => '040101', 'name' => 'AREQUIPA', 'province' => 'AREQUIPA', 'department' => 'AREQUIPA', 'population' => 50_000,
        ]);
        // Pegado al este de Miraflores (centro -77.03 ± 0.01 → borde en -77.02).
        $barranco = District::factory()->squareAround(-77.01, -12.121, 0.01)->create([
            'ubigeo' => '150104', 'name' => 'BARRANCO', 'population' => 40_000,
        ]);
        app(RegionBuilder::class)->build();

        $this->addStat($arequipa, 'robo', 2024, 1, 30);
        $this->addStat($barranco, 'hurto', 2024, 2, 8);
    }

    public function test_cascading_lists(): void
    {
        $this->getJson('/api/v1/geo/departments')
            ->assertOk()
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data', [
                ['code' => '04', 'name' => 'AREQUIPA', 'population' => 50_000],
                ['code' => '07', 'name' => 'CALLAO', 'population' => 400_000],
                ['code' => '15', 'name' => 'LIMA', 'population' => 140_000], // Comas sin población no suma
            ]);

        $this->getJson('/api/v1/geo/departments/15/provinces')
            ->assertOk()
            ->assertJsonPath('data.0', ['code' => '1501', 'name' => 'LIMA', 'population' => 140_000]);

        $this->getJson('/api/v1/geo/provinces/1501/districts')
            ->assertOk()
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data.0.code', '150104')
            ->assertJsonPath('data.2.name', 'MIRAFLORES');

        $this->getJson('/api/v1/geo/departments/99/provinces')->assertNotFound();
        $this->getJson('/api/v1/geo/provinces/1599/districts')->assertNotFound();
    }

    public function test_map_by_levels(): void
    {
        // Sin padre: departamentos del país.
        $this->getJson('/api/v1/map/regions?year=2024&metric=total')
            ->assertOk()
            ->assertJsonPath('properties.level', 'department')
            ->assertJsonPath('properties.child_level', 'province')
            ->assertJsonPath('properties.breadcrumb', [['code' => null, 'level' => 'country', 'name' => 'Perú']])
            ->assertJsonCount(3, 'features')
            ->assertJsonPath('features.0.properties.code', '07')
            ->assertJsonPath('features.0.properties.total', 100)
            ->assertJsonPath('features.1.properties.code', '15')
            ->assertJsonPath('features.1.properties.total', 68)
            ->assertJsonPath('features.0.geometry.type', 'MultiPolygon');

        // Padre = departamento → provincias.
        $this->getJson('/api/v1/map/regions?parent=15&year=2024')
            ->assertOk()
            ->assertJsonPath('properties.level', 'province')
            ->assertJsonPath('properties.parent.name', 'LIMA')
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.properties.code', '1501')
            ->assertJsonPath('features.0.properties.rate', 4.86); // 68 / 140 000 × 10 000

        // Padre = provincia → distritos.
        $this->getJson('/api/v1/map/regions?parent=1501&year=2024')
            ->assertOk()
            ->assertJsonPath('properties.level', 'district')
            ->assertJsonPath('properties.breadcrumb.2.name', 'LIMA')
            ->assertJsonCount(3, 'features')
            ->assertJsonPath('features.0.properties.ubigeo', '150122');
    }

    public function test_rankings_by_level(): void
    {
        $this->getJson('/api/v1/rankings?level=department&year=2024')
            ->assertOk()
            ->assertJsonPath('meta.level', 'department')
            ->assertJsonPath('data.0.code', '04') // 30 / 50 000 = 6 por 10 000
            ->assertJsonPath('data.0.rate', 6)
            ->assertJsonMissingPath('data.0.ubigeo');

        // Sin nivel: distritos (compatibilidad), ahora de todo el país.
        $this->getJson('/api/v1/rankings?year=2024')
            ->assertOk()
            ->assertJsonPath('meta.level', 'district')
            ->assertJsonPath('meta.count', 5);
    }

    public function test_validates_level_and_parent(): void
    {
        $this->getJson('/api/v1/map/regions?level=barrio')->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->getJson('/api/v1/map/regions?parent=1')->assertUnprocessable()->assertJsonValidationErrors('parent');
        $this->getJson('/api/v1/map/regions?parent=99')->assertUnprocessable()->assertJsonValidationErrors('parent');
        $this->getJson('/api/v1/map/regions?level=department&parent=15')->assertUnprocessable()->assertJsonValidationErrors('parent');
    }

    public function test_region_detail_for_department_and_province(): void
    {
        $this->getJson('/api/v1/regions/15?year=2024')
            ->assertOk()
            ->assertJsonPath('data.region.level', 'department')
            ->assertJsonPath('data.total', 68)
            ->assertJsonPath('data.ranking.of', 3)
            ->assertJsonPath('data.ranking.within.name', 'Perú')
            ->assertJsonPath('data.breadcrumb.1.name', 'LIMA')
            ->assertJsonCount(7, 'data.by_crime_type')
            ->assertJsonPath('data.context.averages.0.level', 'country')
            ->assertJsonPath('data.context.neighbors', []);

        $this->getJson('/api/v1/regions/1501?year=2024')
            ->assertOk()
            ->assertJsonPath('data.region.level', 'province')
            ->assertJsonPath('data.ranking.within.code', '15')
            ->assertJsonPath('data.context.averages.0.level', 'department');

        $this->getJson('/api/v1/regions/99')->assertNotFound();
    }

    public function test_district_context_with_averages_and_neighbors(): void
    {
        $this->getJson('/api/v1/regions/150122?year=2024')
            ->assertOk()
            ->assertJsonPath('data.rate', 6)
            ->assertJsonPath('data.ranking.within.code', '1501')
            ->assertJsonPath('data.context.averages.0.level', 'province')
            ->assertJsonPath('data.context.averages.0.rate', 4.86)
            ->assertJsonPath('data.context.averages.1.level', 'department')
            ->assertJsonPath('data.context.averages.2.level', 'country')
            ->assertJsonPath('data.context.averages.2.total', 198) // 60 + 100 + 30 + 8
            ->assertJsonPath('data.context.neighbors.0.ubigeo', '150104')
            ->assertJsonPath('data.context.neighbors.0.rate', 2)
            ->assertJsonCount(1, 'data.context.neighbors');
    }
}
