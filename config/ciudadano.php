<?php

return [
    // Administrador inicial que crea AdminUserSeeder.
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrador'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Prefijos de ubigeo del ámbito: Lima Metropolitana y Callao.
    'ubigeo_prefixes' => ['1501', '0701'],

    // Tolerancia (grados) de ST_Simplify para geom_simplified. 0.0005° ≈ 55 m.
    'simplify_tolerance' => 0.0005,

    // Filas por lote al hacer upsert de crime_stats_monthly.
    'import_chunk_size' => 1000,

    // Rectángulo que contiene Lima Metropolitana y Callao (validación de lat/lng en /districts/locate).
    'bounds' => [
        'lat' => [-12.60, -11.50],
        'lng' => [-77.30, -76.55],
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

    // Minutos que se cachean las respuestas públicas. La caché se invalida al importar.
    'cache_minutes' => 1440,
];
