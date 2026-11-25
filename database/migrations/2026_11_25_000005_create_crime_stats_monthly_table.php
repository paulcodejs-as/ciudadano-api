<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crime_stats_monthly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crime_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(
                ['district_id', 'crime_type_id', 'data_source_id', 'year', 'month'],
                'crime_stats_monthly_unique'
            );
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crime_stats_monthly');
    }
};
