<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Puntos (calor y burbujas) y evolución mes a mes del mapa.
 * Datos: StatsTestData (Miraflores 150122, Callao 070101, Comas 150110 sin población).
 */
class MapPointsTimelineTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStats();
    }

    public function test_points_are_district_centroids_with_values(): void
    {
        $response = $this->getJson('/api/v1/map/points?level=district&parent=1501&year=2024&metric=total')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.level', 'district')
            ->assertJsonPath('meta.parent', '1501')
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.code', '150122')
            ->assertJsonPath('data.0.total', 60);

        // El centroide cae dentro del cuadrado de Miraflores (centro -77.03, -12.12): lng, lat bien ordenados.
        $point = $response->json('data.0');
        $this->assertEqualsWithDelta(-77.03, $point['lng'], 0.02);
        $this->assertEqualsWithDelta(-12.12, $point['lat'], 0.02);
    }

    public function test_points_by_department_level(): void
    {
        $this->getJson('/api/v1/map/points?year=2024')
            ->assertOk()
            ->assertJsonPath('meta.level', 'department')
            ->assertJsonCount(2, 'data');
    }

    public function test_timeline_returns_one_value_per_month(): void
    {
        $response = $this->getJson('/api/v1/map/timeline?level=district&parent=1501&year=2024&metric=total')
            ->assertOk()
            ->assertJsonCount(2, 'data.periods') // el año se recorta al último mes con datos (feb 2024)
            ->assertJsonPath('data.periods.0', '2024-01')
            ->assertJsonPath('meta.metric', 'total')
            ->assertJsonPath('meta.count', 2);

        $items = collect($response->json('data.items'))->keyBy('code');
        $this->assertCount(2, $items['150122']['values']);
        $this->assertSame(60, array_sum($items['150122']['totals']));
        $this->assertSame(0, array_sum($items['150110']['totals']));
        $this->assertSame($response->json('meta.max_value'), max(array_merge(...$items->pluck('values')->all())));
    }

    public function test_timeline_rate_and_validation(): void
    {
        $response = $this->getJson('/api/v1/map/timeline?parent=1501&year=2024')->assertOk()->assertJsonPath('meta.metric', 'rate');
        $comas = collect($response->json('data.items'))->firstWhere('code', '150110');
        $this->assertSame([null, null], $comas['values']); // sin población: sin tasa

        $this->getJson('/api/v1/map/timeline?parent=9')->assertUnprocessable()->assertJsonValidationErrors('parent');
        $this->getJson('/api/v1/map/points?metric=x')->assertUnprocessable()->assertJsonValidationErrors('metric');
    }
}
