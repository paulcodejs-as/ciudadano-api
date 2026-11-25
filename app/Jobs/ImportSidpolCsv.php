<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\Importers\SidpolCsvImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Procesa en cola un CSV SIDPOL subido desde el panel. El importador deja el
 * resultado (o el error) en el registro imports.
 */
class ImportSidpolCsv implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un CSV mal formado no se arregla reintentando. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $importId)
    {
    }

    public function handle(SidpolCsvImporter $importer): void
    {
        $import = Import::find($this->importId);
        if (! $import || $import->status !== Import::STATUS_PENDING) {
            return;
        }

        try {
            $importer->import(Storage::disk('local')->path($import->file_path), $import);
        } catch (Throwable $e) {
            // El importador ya marcó la importación como fallida con el mensaje.
            Log::warning("Importación #{$import->id} fallida: {$e->getMessage()}");
        }
    }

    /** Si el worker muere (timeout, memoria), la importación no queda "running" para siempre. */
    public function failed(Throwable $e): void
    {
        Import::whereKey($this->importId)
            ->whereIn('status', [Import::STATUS_PENDING, Import::STATUS_RUNNING])
            ->update([
                'status' => Import::STATUS_FAILED,
                'log' => 'El proceso se detuvo: '.$e->getMessage(),
                'finished_at' => now(),
            ]);
    }
}
