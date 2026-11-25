<?php

namespace Tests\Feature\Api\V1;

use App\Models\District;
use App\Services\StatsCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapAndRankingsTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStats();
    }

    public function test_map_returns_feature_collection(): void
    {
        $response = $this->get('/api/v1/map/districts?from=2024-01&to=2024-02')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/geo+json')
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonCount(3, 'features')
            ->assertJsonPath('properties.metric', 'rate')
            ->assertJsonPath('properties.max_value', 6)
            ->assertJsonPath('properties.min_value', 2.5);

        $first = $response->json('features.0');
        $this->assertSame('MultiPolygon', $first['geometry']['type']);
        $this->assertSame('150122', $first['properties']['ubigeo']);
        $this->assertSame(1, $first['properties']['rank']);
        // GeoJSON en orden lng, lat.
        $this->assertEqualsWithDelta(-77.04, $first['geometry']['coordinates'][0][0][0][0], 0.0001);
    }

    public function test_map_filters_by_crime_type(): void
    {
        $this->getJson('/api/v1/map/districts?year=2024&crime_type=hurto&metric=total')
            ->assertOk()
            ->assertJsonPath('features.0.properties.ubigeo', '150122')
            ->assertJsonPath('features.0.properties.total', 10)
            ->assertJsonPath('features.1.properties.total', 0);
    }

    public function test_rankings_by_rate_and_by_total(): void
    {
        $this->getJson('/api/v1/rankings?from=2024-01&to=2024-02')
            ->assertOk()
            ->assertJsonPath('meta.metric', 'rate')
            ->assertJsonPath('data.0.ubigeo', '150122')
            ->assertJsonPath('data.1.ubigeo', '070101')
            // Sin población: sin tasa ni posición, al final.
            ->assertJsonPath('data.2.ubigeo', '150110')
            ->assertJsonPath('data.2.rate', null)
            ->assertJsonPath('data.2.rank', null);

        $this->getJson('/api/v1/rankings?from=2024-01&to=2024-02&metric=total')
            ->assertOk()
            ->assertJsonPath('meta.metric', 'total')
            ->assertJsonPath('data.0.ubigeo', '070101')
            ->assertJsonPath('data.0.value', 100)
            ->assertJsonPath('data.2.rank', 3);
    }

    public function test_falls_back_to_total_without_population(): void
    {
        District::query()->update(['population' => null]);

        $response = $this->getJson('/api/v1/rankings?year=2024')
            ->assertOk()
            ->assertJsonPath('meta.metric', 'total')
            ->assertJsonPath('meta.metric_requested', 'rate')
            ->assertJsonPath('data.0.ubigeo', '070101');

        $this->assertStringContainsString('población', implode(' ', $response->json('meta.notices')));
    }

    public function test_default_range_is_last_twelve_months_with_data(): void
    {
        // Datos más antiguos que la ventana de 12 meses.
        $this->addStat(District::where('ubigeo', '150122')->first(), 'robo', 2023, 1, 1);

        $this->getJson('/api/v1/rankings')
            ->assertOk()
            ->assertJsonPath('meta.from', '2023-03')
            ->assertJsonPath('meta.to', '2024-02')
            ->assertJsonPath('meta.latest_period', '2024-02');
    }

    public function test_default_range_starts_at_first_month_with_data(): void
    {
        // Solo hay datos de ene y feb 2024: no se rellenan 10 meses previos en 0.
        $this->getJson('/api/v1/districts/150122')
            ->assertOk()
            ->assertJsonPath('meta.from', '2024-01')
            ->assertJsonPath('meta.to', '2024-02')
            ->assertJsonCount(2, 'data.monthly');

        // Un rango explícito se respeta aunque empiece antes de los datos.
        $this->getJson('/api/v1/districts/150122?from=2023-11&to=2024-02')
            ->assertJsonPath('meta.from', '2023-11')
            ->assertJsonCount(4, 'data.monthly');
    }

    public function test_shows_virtual_reports_notice_after_cutoff(): void
    {
        $notices = fn (string $q) => implode(' ', $this->getJson("/api/v1/rankings?{$q}")->json('meta.notices'));

        $this->assertStringContainsString('noviembre de 2024', $notices('from=2024-06&to=2024-12'));
        $this->assertStringNotContainsString('noviembre de 2024', $notices('from=2024-01&to=2024-10'));
        $this->assertStringNotContainsString('noviembre de 2024', $notices('from=2024-06&to=2024-12&crime_type=extorsion'));
    }

    public function test_validates_filters(): void
    {
        $this->getJson('/api/v1/rankings?from=2024-1')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/rankings?from=2024-05&to=2024-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/rankings?year=2024&from=2024-01')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/v1/rankings?crime_type=piraterIa')->assertUnprocessable()->assertJsonValidationErrors('crime_type');
        $this->getJson('/api/v1/rankings?metric=avg')->assertUnprocessable()->assertJsonValidationErrors('metric');
        $this->getJson('/api/v1/map/districts?from=2010-01')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_responses_are_cached_until_flush(): void
    {
        $this->getJson('/api/v1/rankings?year=2024&metric=total')->assertJsonPath('data.0.total', 100);

        $this->addStat(District::where('ubigeo', '070101')->first(), 'robo', 2024, 2, 5);
        $this->getJson('/api/v1/rankings?year=2024&metric=total')->assertJsonPath('data.0.total', 100);

        app(StatsCache::class)->flush();
        $this->getJson('/api/v1/rankings?year=2024&metric=total')->assertJsonPath('data.0.total', 105);
    }
}
