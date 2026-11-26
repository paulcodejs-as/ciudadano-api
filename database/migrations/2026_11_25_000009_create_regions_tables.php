<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance nacional: departamentos y provincias (para el mapa por niveles y los
 * filtros en cascada) y códigos derivados del ubigeo en districts.
 *
 * Las geometrías de departamentos y provincias son la unión de sus distritos
 * (ciudadano:build-regions), ya simplificadas: solo se usan para dibujar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name');
            $table->unsignedInteger('population')->nullable();
            $table->decimal('area_km2', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->char('code', 4)->unique();
            $table->char('department_code', 2)->index();
            $table->string('name');
            $table->unsignedInteger('population')->nullable();
            $table->decimal('area_km2', 12, 2)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE departments ADD COLUMN geom_simplified MULTIPOLYGON NULL SRID 4326 AFTER area_km2');
        DB::statement('ALTER TABLE provinces ADD COLUMN geom_simplified MULTIPOLYGON NULL SRID 4326 AFTER area_km2');

        // Códigos derivados del ubigeo: DDPPDD → departamento DD, provincia DDPP.
        Schema::table('districts', function (Blueprint $table) {
            $table->char('department_code', 2)->storedAs('LEFT(ubigeo, 2)')->after('ubigeo')->index();
            $table->char('province_code', 4)->storedAs('LEFT(ubigeo, 4)')->after('department_code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->dropIndex(['department_code']);
            $table->dropIndex(['province_code']);
            $table->dropColumn(['department_code', 'province_code']);
        });
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('departments');
    }
};
