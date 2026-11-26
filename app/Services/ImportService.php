<?php

namespace App\Services;

use App\Jobs\ImportSidpolCsv;
use App\Models\DataSource;
use App\Models\District;
use App\Models\Import;
use App\Models\User;
use App\Services\Importers\SidpolCsvImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Importaciones desde el panel admin y la API: guardar el archivo, encolar, resumir. */
class ImportService
{
    public function __construct(private readonly CrimeStatsService $stats)
    {
    }

    /**
     * Guarda el archivo (CSV o .xlsx) en storage/app/imports y encola su procesamiento.
     * El archivo se conserva: `ciudadano:load-data` lo vuelve a cargar después de migrar.
     */
    public function queueSidpolUpload(UploadedFile $file, User $user): Import
    {
        $source = DataSource::where('slug', DataSource::SIDPOL)->firstOrFail();

        $extension = SidpolCsvImporter::detectFormat($file->getRealPath()) === 'xlsx' ? 'xlsx' : 'csv';
        $path = $file->storeAs(
            'imports',
            now()->format('Ymd_His').'_'.Str::random(6).'.'.$extension,
            'local'
        );

        $import = Import::create([
            'data_source_id' => $source->id,
            'user_id' => $user->id,
            'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'file_path' => $path,
            'status' => Import::STATUS_PENDING,
        ]);

        // Con QUEUE_CONNECTION=sync se procesa aquí mismo; con "database" lo hace queue:work.
        ImportSidpolCsv::dispatch($import->id);

        return $import->fresh(['user']);
    }

    /** Límite efectivo de subida en bytes: el menor entre la app y php.ini. */
    public function uploadLimitBytes(): int
    {
        return min(
            (int) config('ciudadano.max_upload_mb', 64) * 1024 * 1024,
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        );
    }

    /** Resumen de lo que hay cargado, para el panel. */
    public function dataSummary(): array
    {
        $totals = DB::table('crime_stats_monthly')
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(count), 0) AS total')
            ->first();

        return [
            'districts' => District::inScope()->count(),
            'districts_with_population' => District::inScope()->whereNotNull('population')->count(),
            'from' => $this->stats->earliestPeriod()?->format('Y-m'),
            'to' => $this->stats->latestPeriod()?->format('Y-m'),
            'monthly_rows' => (int) $totals->rows_count,
            'total_reports' => (int) $totals->total,
            'last_import' => Import::where('status', Import::STATUS_DONE)->latest('finished_at')->first(),
        ];
    }

    /** "2M" → 2097152. Cero o negativo en php.ini significa sin límite. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (int) $value <= 0) {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
