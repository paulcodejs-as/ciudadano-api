<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distritos de Lima Metropolitana y Callao.
 *
 * Las geometrías usan los tipos espaciales nativos de MySQL 8 con SRID 4326
 * (WGS84). Se crean con SQL directo porque Laravel 10 no permite fijar el SRID
 * en columnas MULTIPOLYGON y el índice SPATIAL exige NOT NULL + SRID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->char('ubigeo', 6)->unique();
            $table->string('name');
            $table->string('province');
            $table->string('department');
            $table->unsignedInteger('population')->nullable();
            $table->decimal('area_km2', 10, 2)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE districts
            ADD COLUMN geom MULTIPOLYGON NOT NULL SRID 4326 AFTER area_km2,
            ADD COLUMN geom_simplified MULTIPOLYGON NULL SRID 4326 AFTER geom,
            ADD SPATIAL INDEX districts_geom_spatial (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
