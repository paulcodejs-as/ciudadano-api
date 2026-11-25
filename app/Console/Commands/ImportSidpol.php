<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Importers\SidpolCsvImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportSidpol extends Command
{
    protected $signature = 'ciudadano:import-sidpol
                            {path=storage/app/data/denuncias.csv : Ruta al CSV "Denuncias Policiales"}
                            {--user= : Email del admin que queda registrado en la importación}';

    protected $description = 'Importa los conteos mensuales de denuncias SIDPOL de Lima Metropolitana y Callao';

    public function handle(SidpolCsvImporter $importer): int
    {
        $path = is_file($this->argument('path')) ? $this->argument('path') : base_path($this->argument('path'));

        $userId = null;
        if ($email = $this->option('user')) {
            $userId = User::where('email', $email)->value('id');
            if ($userId === null) {
                $this->error("No existe el usuario {$email}.");

                return self::FAILURE;
            }
        }

        $this->info("Importando denuncias desde {$path} (puede tardar un par de minutos) ...");

        try {
            $import = $importer->import($path, null, $userId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line((string) $import->log);
        $this->info("Importación #{$import->id} terminada.");

        return self::SUCCESS;
    }
}
