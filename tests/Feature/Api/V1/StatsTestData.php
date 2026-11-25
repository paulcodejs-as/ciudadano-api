<?php

namespace Tests\Feature\Api\V1;

use App\Models\CrimeType;
use App\Models\DataSource;
use App\Models\District;
use Database\Seeders\CrimeTypeSeeder;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos comunes de los tests de estadísticas:
 * - Miraflores (150122): 100 000 hab., robo ene-2024 = 50, hurto feb-2024 = 10 → total 60, tasa 6.0
 * - Callao (070101): 400 000 hab., robo ene-2024 = 100 → total 100, tasa 2.5
 * - Comas (150110): sin población ni denuncias.
 */
trait StatsTestData
{
    protected function seedStats(): void
    {
        $this->seed([CrimeTypeSeeder::class, DataSourceSeeder::class]);

        $miraflores = District::factory()->squareAround(-77.03, -12.121, 0.01)
            ->create(['ubigeo' => '150122', 'name' => 'MIRAFLORES', 'population' => 100_000]);
        $callao = District::factory()->callao()->squareAround(-77.13, -12.05, 0.01)
            ->create(['ubigeo' => '070101', 'name' => 'CALLAO', 'population' => 400_000]);
        District::factory()->squareAround(-77.05, -11.93, 0.01)
            ->create(['ubigeo' => '150110', 'name' => 'COMAS', 'population' => null]);

        $this->addStat($miraflores, 'robo', 2024, 1, 50);
        $this->addStat($miraflores, 'hurto', 2024, 2, 10);
        $this->addStat($callao, 'robo', 2024, 1, 100);
    }

    protected function addStat(District $district, string $slug, int $year, int $month, int $count): void
    {
        DB::table('crime_stats_monthly')->insert([
            'district_id' => $district->id,
            'crime_type_id' => CrimeType::where('slug', $slug)->value('id'),
            'data_source_id' => DataSource::where('slug', DataSource::SIDPOL)->value('id'),
            'year' => $year,
            'month' => $month,
            'count' => $count,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
