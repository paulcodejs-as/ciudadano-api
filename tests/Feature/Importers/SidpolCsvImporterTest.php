<?php

namespace Tests\Feature\Importers;

use App\Models\CrimeStatMonthly;
use App\Models\District;
use App\Models\Import;
use App\Models\User;
use App\Services\Importers\SidpolCsvImporter;
use Database\Seeders\CrimeTypeSeeder;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidpolCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CrimeTypeSeeder::class, DataSourceSeeder::class]);
        District::factory()->create(['ubigeo' => '150122', 'name' => 'MIRAFLORES']);
        District::factory()->callao()->create(['ubigeo' => '070101', 'name' => 'CALLAO']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_imports_aggregates_and_skips(): void
    {
        $import = app(SidpolCsvImporter::class)->import($this->csv(self::sampleRows()));

        $this->assertSame(Import::STATUS_DONE, $import->status);
        $this->assertSame(8, $import->rows_read);
        $this->assertSame(5, $import->rows_imported);
        $this->assertSame(3, $import->rows_skipped);

        // Miraflores, robo, ene 2024: 3 + 2 (fila repetida se suma).
        $this->assertSame(5, $this->monthlyCount('150122', 'robo', 2024, 1));
        // Cantidad decimal "4.0" → 4; ubigeo sin 0 inicial → Callao.
        $this->assertSame(4, $this->monthlyCount('070101', 'hurto', 2024, 1));
        // Acentos y variante larga de la modalidad.
        $this->assertSame(1, $this->monthlyCount('150122', 'extorsion', 2024, 2));
        // Cantidad vacía → 0.
        $this->assertSame(0, $this->monthlyCount('150122', 'violencia-contra-la-mujer', 2024, 2));

        $this->assertStringContainsString('Trata de personas', $import->log);
        $this->assertStringContainsString('150199', $import->log);
    }

    public function test_is_idempotent(): void
    {
        $path = $this->csv(self::sampleRows());
        app(SidpolCsvImporter::class)->import($path);
        app(SidpolCsvImporter::class)->import($path);

        $this->assertSame(4, CrimeStatMonthly::count());
        $this->assertSame(5, $this->monthlyCount('150122', 'robo', 2024, 1));
        $this->assertSame(2, Import::count());
    }

    public function test_reads_semicolon_bom_and_windows_1252(): void
    {
        $content = "\xEF\xBB\xBF".implode("\r\n", [
            'anio;mes;dpto_hecho;prov_hecho;dist_hecho;ubigeo_hecho;p_modalidades;cantidad',
            mb_convert_encoding('2023;12;LIMA;LIMA;MIRAFLORES;150122;Extorsión;7', 'Windows-1252', 'UTF-8'),
        ]);
        $path = $this->tempFile($content);

        $import = app(SidpolCsvImporter::class)->import($path);

        $this->assertSame(1, $import->rows_imported);
        $this->assertSame(7, $this->monthlyCount('150122', 'extorsion', 2023, 12));
    }

    public function test_accepts_registro_column_names(): void
    {
        $path = $this->tempFile(implode("\n", [
            'ANIO_REGISTRO,MES_REGISTRO,DPTO_HECHO_NEW,PROV_HECHO,DIST_HECHO,UBIGEO_HECHO,P_MODALIDADES,cantidad',
            '2026,3,LIMA,LIMA,MIRAFLORES,150122,Violencia contra la mujer e integrantes,6',
        ]));

        $import = app(SidpolCsvImporter::class)->import($path);

        $this->assertSame(1, $import->rows_imported);
        $this->assertSame(6, $this->monthlyCount('150122', 'violencia-contra-la-mujer', 2026, 3));
    }

    public function test_fails_with_missing_columns(): void
    {
        $path = $this->tempFile("ANIO,MES,UBIGEO\n2024,1,150122\n");

        try {
            app(SidpolCsvImporter::class)->import($path);
            $this->fail('Debió lanzar una excepción.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Faltan columnas', $e->getMessage());
        }

        $this->assertSame(Import::STATUS_FAILED, Import::first()->status);
    }

    public function test_fails_without_districts(): void
    {
        District::query()->delete();

        $this->expectExceptionMessage('ciudadano:import-districts');
        app(SidpolCsvImporter::class)->import($this->csv(self::sampleRows()));
    }

    public function test_command_records_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->artisan('ciudadano:import-sidpol', ['path' => $this->csv(self::sampleRows()), '--user' => $admin->email])
            ->assertSuccessful();

        $this->assertSame($admin->id, Import::first()->user_id);
    }

    public function test_command_fails_with_missing_file(): void
    {
        $this->artisan('ciudadano:import-sidpol', ['path' => 'no/existe.csv'])->assertFailed();
    }

    private function monthlyCount(string $ubigeo, string $slug, int $year, int $month): ?int
    {
        return CrimeStatMonthly::query()
            ->whereHas('district', fn ($q) => $q->where('ubigeo', $ubigeo))
            ->whereHas('crimeType', fn ($q) => $q->where('slug', $slug))
            ->where(['year' => $year, 'month' => $month])
            ->value('count');
    }

    private static function sampleRows(): array
    {
        return [
            ['ANIO', 'MES', 'DPTO_HECHO', 'PROV_HECHO', 'DIST_HECHO', 'UBIGEO_HECHO', 'P_MODALIDADES', 'cantidad'],
            ['2024', '1', 'LIMA', 'LIMA', 'MIRAFLORES', '150122', 'Robo', '3'],
            ['2024', '1', 'LIMA', 'LIMA', 'MIRAFLORES', '150122', 'Robo', '2'],
            ['2024', '1', 'CALLAO', 'CALLAO', 'CALLAO', '70101', 'Hurto', '4.0'],
            ['2024', '2', 'LIMA', 'LIMA', 'MIRAFLORES', '150122', 'Extorsión', '1'],
            ['2024', '2', 'LIMA', 'LIMA', 'MIRAFLORES', '150122', 'VIOLENCIA CONTRA LA MUJER E INTEGRANTES DEL GRUPO FAMILIAR', ''],
            ['2024', '2', 'AREQUIPA', 'AREQUIPA', 'AREQUIPA', '40101', 'Robo', '9'],     // fuera del ámbito
            ['2024', '2', 'LIMA', 'LIMA', 'INEXISTENTE', '150199', 'Robo', '9'],         // distrito sin polígono
            ['2024', '2', 'LIMA', 'LIMA', 'MIRAFLORES', '150122', 'Trata de personas', '9'], // modalidad desconocida
        ];
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);

        return $this->tempFile(stream_get_contents($handle));
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sidpol');
        file_put_contents($path, $content);

        return $this->tempFiles[] = $path;
    }
}
