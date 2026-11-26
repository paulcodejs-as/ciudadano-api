<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Descarga los polígonos de los distritos del Perú desde la capa oficial
 * "Distritos INEI 2025" (PNUD, ArcGIS FeatureServer), departamento por
 * departamento, y los junta en un solo GeoJSON (WGS84) listo para
 * ciudadano:import-districts. Incluye pob_2024 (población 2024).
 */
class FetchDistricts extends Command
{
    private const URL = 'https://geosmarthosting.undp.org/arcgis/rest/services/Hosted/Distritos_INEI_2025_IDE_2024/FeatureServer/0/query';

    protected $signature = 'ciudadano:fetch-districts
                            {path=storage/app/data/peru_distritos_inei2025.geojson : Archivo de salida}';

    protected $description = 'Descarga los distritos del Perú (INEI 2025, PNUD) a un GeoJSON';

    public function handle(): int
    {
        $path = str_starts_with($this->argument('path'), DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:/', $this->argument('path'))
            ? $this->argument('path')
            : base_path($this->argument('path'));

        $out = fopen($path.'.tmp', 'wb');
        fwrite($out, '{"type":"FeatureCollection","features":[');

        $total = 0;
        $first = true;
        $bar = $this->output->createProgressBar(25);
        $bar->start();

        try {
            foreach (range(1, 25) as $n) {
                $code = sprintf('%02d', $n);
                $response = Http::timeout(180)->retry(3, 2000)->get(self::URL, [
                    'where' => "ccdd='{$code}'",
                    'outFields' => 'ubigeo,departamen,provincia,distrito,pob_2024',
                    'outSR' => 4326,
                    'f' => 'geojson',
                ])->throw();

                $features = $response->json('features') ?? [];
                if ($features === []) {
                    throw new \RuntimeException("El departamento {$code} no devolvió distritos.");
                }

                foreach ($features as $feature) {
                    fwrite($out, ($first ? '' : ',').json_encode($feature, JSON_UNESCAPED_UNICODE));
                    $first = false;
                    $total++;
                }
                $bar->advance();
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($path.'.tmp');
            $this->newLine();
            $this->error('No se pudo descargar: '.$e->getMessage());

            return self::FAILURE;
        }

        fwrite($out, ']}');
        fclose($out);
        rename($path.'.tmp', $path);

        $bar->finish();
        $this->newLine();
        $this->info(sprintf('%d distritos guardados en %s (%.1f MB).', $total, $path, filesize($path) / 1048576));

        return self::SUCCESS;
    }
}
