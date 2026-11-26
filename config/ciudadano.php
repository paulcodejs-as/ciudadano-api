<?php

return [
    // Administrador inicial que crea AdminUserSeeder.
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrador'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Ámbito: prefijos de ubigeo separados por coma en UBIGEO_PREFIXES (p. ej. "1501,0701" = Lima
    // Metropolitana y Callao). Vacío = todo el Perú.
    'ubigeo_prefixes' => array_values(array_filter(explode(',', (string) env('UBIGEO_PREFIXES', '')))),

    // Tolerancia (grados) de ST_Simplify para geom_simplified. 0.0005° ≈ 55 m.
    'simplify_tolerance' => 0.0005,

    // Tolerancia (grados) de los contornos de provincias y departamentos (unión de distritos).
    'region_tolerance' => [
        'province' => 0.002,
        'department' => 0.006,
    ],

    // Filas por lote al hacer upsert de crime_stats_monthly.
    'import_chunk_size' => 1000,

    // Rectángulo que contiene el Perú (validación de lat/lng en /districts/locate).
    'bounds' => [
        'lat' => [-18.40, 0.05],
        'lng' => [-81.40, -68.60],
    ],

    // Primer año con datos SIDPOL.
    'first_year' => 2018,

    // Meses por defecto cuando no se envía rango: los últimos N meses con datos.
    'default_months' => 12,

    // Desde este mes SIDPOL excluye las denuncias virtuales de robo/hurto (salto en la serie).
    'virtual_reports_cutoff' => '2024-11',

    // Fuente de districts.population; se muestra en meta.population_source.
    // Por defecto: campo pob_2024 de la capa "Distritos INEI 2025" del PNUD (IDE 2024).
    'population_source' => env('POPULATION_SOURCE', 'Población 2024 (PNUD, IDE 2024, a partir de datos del INEI)'),

    // Carpetas donde `ciudadano:load-data` busca los archivos SIDPOL (CSV o .xlsx) para
    // volver a cargarlos después de migrar: los que dejas a mano y los subidos por el panel.
    'data_paths' => [storage_path('app/data'), storage_path('app/imports')],

    // GeoJSON de distritos que `ciudadano:load-data` importa si la tabla está vacía.
    'districts_geojson' => storage_path('app/data/peru_distritos_inei2025.geojson'),

    // `php artisan db:seed` (y migrate --seed) corre ciudadano:load-data. Pon false para
    // sembrar solo los catálogos y el admin.
    'seed_data_files' => env('SEED_DATA_FILES', true),

    // Tamaño máximo (MB) del CSV que se sube desde el panel. El límite real es el menor
    // entre este valor y upload_max_filesize / post_max_size de php.ini.
    'max_upload_mb' => 64,

    // Minutos que se cachean las respuestas públicas. La caché se invalida al importar.
    'cache_minutes' => 1440,
];
