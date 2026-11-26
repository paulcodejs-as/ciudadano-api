<?php

namespace App\Console\Commands;

use App\Services\Importers\RegionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildRegions extends Command
{
    protected $signature = 'ciudadano:build-regions';

    protected $description = 'Arma departamentos y provincias (nombre, población, área y contorno) a partir de los distritos';

    public function handle(RegionBuilder $builder): int
    {
        ini_set('memory_limit', '1024M');

        $total = DB::table('districts')->distinct()->count('province_code');
        if ($total === 0) {
            $this->error('No hay distritos. Corre antes ciudadano:import-districts.');

            return self::FAILURE;
        }

        $this->info('Uniendo distritos en provincias y departamentos…');
        $bar = $this->output->createProgressBar($total);
        $result = $builder->build(fn () => $bar->advance());
        $bar->finish();
        $this->newLine();

        foreach ($result['log'] as $line) {
            $this->warn($line);
        }
        $this->info("Listo: {$result['provinces']} provincias y {$result['departments']} departamentos con contorno.");

        return self::SUCCESS;
    }
}
