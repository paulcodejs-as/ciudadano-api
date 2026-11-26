<?php

namespace Tests\Feature\Importers;

use App\Models\District;
use App\Services\Importers\PopulationCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\StatsTestData;
use Tests\TestCase;

class PopulationCsvImporterTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Estos tests comprueban el filtro por ámbito: se fija en Lima Metropolitana y Callao.
        config(["ciudadano.ubigeo_prefixes" => ["1501", "0701"]]);
        $this->seedStats(); // Miraflores 100 000, Callao 400 000, Comas sin población
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_updates_population_by_ubigeo(): void
    {
        $path = $this->tempFile(implode("\n", [
            'ubigeo,distrito,poblacion',
            '150110,Comas,"520,450"',     // separador de miles
            '70101,Callao,451260.0',      // 0 inicial perdido y decimal
            '150122,Miraflores,',         // vacío: no borra el valor actual
            '040101,Arequipa,55437',      // fuera del ámbito
            '150199,Inexistente,1000',    // distrito no importado
        ]));

        $result = app(PopulationCsvImporter::class)->import($path);

        $this->assertSame(5, $result['read']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(520_450, District::where('ubigeo', '150110')->value('population'));
        $this->assertSame(451_260, District::where('ubigeo', '070101')->value('population'));
        $this->assertSame(100_000, District::where('ubigeo', '150122')->value('population'));
        $this->assertStringContainsString('150199', implode(' ', $result['log']));
    }

    public function test_accepts_semicolon_and_other_headers(): void
    {
        $path = $this->tempFile("\xEF\xBB\xBFUBIGEO;NOMBDIST;POBLACIÓN\n150110;COMAS;1 038 495\n");

        app(PopulationCsvImporter::class)->import($path);

        $this->assertSame(1_038_495, District::where('ubigeo', '150110')->value('population'));
    }

    public function test_population_enables_rate_in_api(): void
    {
        District::query()->update(['population' => null]);
        $this->getJson('/api/v1/rankings?year=2024')->assertJsonPath('meta.metric', 'total');

        $this->artisan('ciudadano:import-population', [
            'path' => $this->tempFile("ubigeo,poblacion\n150122,100000\n070101,400000\n"),
        ])->assertSuccessful();

        // La caché se invalida sola tras importar.
        $this->getJson('/api/v1/rankings?year=2024')
            ->assertJsonPath('meta.metric', 'rate')
            ->assertJsonPath('meta.population_source', config('ciudadano.population_source'))
            ->assertJsonPath('data.0.ubigeo', '150122');
    }

    public function test_bundled_template_has_fifty_districts(): void
    {
        $rows = array_map('str_getcsv', file(base_path('database/data/poblacion_distritos.csv'), FILE_IGNORE_NEW_LINES));
        $ubigeos = array_column(array_slice($rows, 1), 0);

        $this->assertCount(50, $ubigeos);
        $this->assertCount(50, array_unique($ubigeos));
        foreach ($ubigeos as $ubigeo) {
            $this->assertMatchesRegularExpression('/^(1501|0701)\d{2}$/', $ubigeo);
        }
    }

    public function test_command_fails_with_empty_template(): void
    {
        $this->artisan('ciudadano:import-population', [
            'path' => $this->tempFile("ubigeo,distrito,poblacion\n150122,Miraflores,\n"),
        ])->assertFailed();
    }

    public function test_fails_without_required_columns(): void
    {
        $this->expectExceptionMessage('columna de ubigeo y otra de población');
        app(PopulationCsvImporter::class)->import($this->tempFile("codigo_x,habitantes\n150122,1\n"));
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pob');
        file_put_contents($path, $content);

        return $this->tempFiles[] = $path;
    }
}
