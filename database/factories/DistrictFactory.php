<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\District>
 */
class DistrictFactory extends Factory
{
    public function definition(): array
    {
        // Un cuadrado pequeño dentro de Lima Metropolitana.
        $lng = $this->faker->randomFloat(4, -77.10, -76.90);
        $lat = $this->faker->randomFloat(4, -12.20, -11.95);

        return [
            'ubigeo' => '1501'.$this->faker->unique()->numerify('##'),
            'name' => mb_strtoupper($this->faker->unique()->city()),
            'province' => 'LIMA',
            'department' => 'LIMA',
            'population' => $this->faker->numberBetween(20_000, 900_000),
            'area_km2' => $this->faker->randomFloat(2, 2, 120),
            'geom' => self::square($lng, $lat, 0.01),
            'geom_simplified' => self::square($lng, $lat, 0.01),
        ];
    }

    /** Polígono cuadrado (lado = 2 × $half grados) centrado en lng/lat. */
    public function squareAround(float $lng, float $lat, float $half = 0.01): static
    {
        return $this->state(fn () => [
            'geom' => self::square($lng, $lat, $half),
            'geom_simplified' => self::square($lng, $lat, $half),
        ]);
    }

    public function callao(): static
    {
        return $this->state(fn () => [
            'ubigeo' => '0701'.$this->faker->unique()->numerify('##'),
            'province' => 'CALLAO',
            'department' => 'CALLAO',
        ]);
    }

    public static function square(float $lng, float $lat, float $half): \Illuminate\Database\Query\Expression
    {
        $w = $lng - $half;
        $e = $lng + $half;
        $s = $lat - $half;
        $n = $lat + $half;
        $wkt = "MULTIPOLYGON((($w $s,$e $s,$e $n,$w $n,$w $s)))";

        // axis-order=long-lat: el WKT viene como "lng lat" (igual que GeoJSON).
        return DB::raw("ST_GeomFromText('{$wkt}', 4326, 'axis-order=long-lat')");
    }
}
