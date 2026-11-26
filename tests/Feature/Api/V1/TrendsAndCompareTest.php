<?php

namespace Tests\Feature\Api\V1;

use App\Models\District;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cambio interanual (mismos meses del año anterior) y /districts/compare.
 *
 * Datos (StatsTestData + 2023):
 * - Miraflores: ene-feb 2024 = 50 robo + 10 hurto = 60; ene-feb 2023 = 40 robo → +50 %
 * - Callao:     ene-feb 2024 = 100 robo;               ene-feb 2023 = 125 robo → −20 %
 * - Comas:      sin denuncias ningún año → sin cambio (null)
 */
class TrendsAndCompareTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStats();
        $this->addStat(District::where('ubigeo', '150122')->first(), 'robo', 2023, 1, 40);
        $this->addStat(District::where('ubigeo', '070101')->first(), 'robo', 2023, 2, 125);
    }

    public function test_rankings_include_year_over_year_change(): void
    {
        $this->getJson('/api/v1/rankings?from=2024-01&to=2024-02&metric=total')
            ->assertOk()
            ->assertJsonPath('meta.comparison', ['available' => true, 'from' => '2023-01', 'to' => '2023-02'])
            ->assertJsonPath('data.0.ubigeo', '070101')
            ->assertJsonPath('data.0.previous_total', 125)
            ->assertJsonPath('data.0.change_pct', -20)
            ->assertJsonPath('data.1.ubigeo', '150122')
            ->assertJsonPath('data.1.change_pct', 50)
            ->assertJsonPath('data.2.change_pct', null);
    }

    public function test_change_metric_ranks_biggest_increase_first(): void
    {
        $this->getJson('/api/v1/rankings?from=2024-01&to=2024-02&metric=change')
            ->assertOk()
            ->assertJsonPath('meta.metric', 'change')
            ->assertJsonPath('data.0.ubigeo', '150122')
            ->assertJsonPath('data.0.value', 50)
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.1.value', -20)
            ->assertJsonPath('data.2.rank', null);

        $this->getJson('/api/v1/map/districts?from=2024-01&to=2024-02&metric=change')
            ->assertOk()
            ->assertJsonPath('properties.metric', 'change')
            ->assertJsonPath('properties.max_value', 50)
            ->assertJsonPath('properties.min_value', -20)
            ->assertJsonPath('features.0.properties.change_pct', 50);
    }

    public function test_change_is_unavailable_without_previous_year_data(): void
    {
        // 2023 completo exigiría datos desde ene 2022: no los hay.
        $response = $this->getJson('/api/v1/rankings?year=2023&metric=change')
            ->assertOk()
            ->assertJsonPath('meta.comparison.available', false)
            ->assertJsonPath('data.0.change_pct', null)
            ->assertJsonPath('data.0.value', null);

        $this->assertStringContainsString('año anterior', implode(' ', $response->json('meta.notices')));
    }

    public function test_warns_when_comparison_crosses_sidpol_cutoff(): void
    {
        $notices = fn (string $q) => implode(' ', $this->getJson("/api/v1/rankings?{$q}")->json('meta.notices'));

        // 2025 frente a 2024 (que incluye meses antes de nov 2024): distorsionado.
        $this->addStat(District::where('ubigeo', '150122')->first(), 'robo', 2024, 12, 1);
        $this->addStat(District::where('ubigeo', '150122')->first(), 'robo', 2025, 12, 1);
        $this->assertStringContainsString('distorsionado', $notices('year=2025'));
        $this->assertStringNotContainsString('distorsionado', $notices('year=2025&crime_type=extorsion'));
        $this->assertStringNotContainsString('distorsionado', $notices('from=2024-01&to=2024-02'));
    }

    public function test_district_detail_includes_change_and_previous_series(): void
    {
        $this->getJson('/api/v1/districts/150122?from=2024-01&to=2024-02')
            ->assertOk()
            ->assertJsonPath('data.total', 60)
            ->assertJsonPath('data.previous_total', 40)
            ->assertJsonPath('data.change_pct', 50)
            ->assertJsonPath('data.monthly.0', ['period' => '2024-01', 'total' => 50, 'rate' => 5, 'previous_total' => 40])
            ->assertJsonPath('data.monthly.1.previous_total', 0)
            ->assertJsonPath('data.by_crime_type.0.slug', 'robo')
            ->assertJsonPath('data.by_crime_type.0.previous_total', 40)
            ->assertJsonPath('data.by_crime_type.0.change_pct', 25);
    }

    public function test_compares_districts_in_requested_order(): void
    {
        $this->getJson('/api/v1/districts/compare?ubigeos=070101,150122&from=2024-01&to=2024-02')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.district.ubigeo', '070101')
            ->assertJsonPath('data.0.total', 100)
            ->assertJsonPath('data.0.rate', 2.5)
            ->assertJsonPath('data.0.change_pct', -20)
            ->assertJsonPath('data.1.district.name', 'MIRAFLORES')
            ->assertJsonPath('data.1.ranking.position', 1)
            ->assertJsonCount(2, 'data.1.monthly')
            ->assertJsonCount(7, 'data.1.by_crime_type');
    }

    public function test_compare_validates_ubigeos(): void
    {
        $this->getJson('/api/v1/districts/compare')->assertUnprocessable()->assertJsonValidationErrors('ubigeos');
        $this->getJson('/api/v1/districts/compare?ubigeos=150122')->assertUnprocessable()->assertJsonValidationErrors('ubigeos');
        $this->getJson('/api/v1/districts/compare?ubigeos=150122,1501')->assertUnprocessable();
        $this->getJson('/api/v1/districts/compare?ubigeos=150122,070101,150110,150101,150102')->assertUnprocessable();
        $this->getJson('/api/v1/districts/compare?ubigeos=150122,150122')->assertUnprocessable()->assertJsonPath('success', false);
        $this->getJson('/api/v1/districts/compare?ubigeos=150122,150199')->assertNotFound()->assertJsonPath('message', 'Distritos no encontrados: 150199.');
    }
}
