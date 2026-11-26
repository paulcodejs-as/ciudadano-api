<?php

namespace App\Models;

use App\Support\UbigeoScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Distrito con su polígono (MySQL MULTIPOLYGON, SRID 4326).
 *
 * Las columnas geom y geom_simplified son binarias: nunca se cargan por defecto
 * (ver scope "withoutGeometry" y $hidden). Para leerlas usa ST_AsGeoJSON en SQL
 * desde GeoService.
 */
class District extends Model
{
    use HasFactory;

    public const SPATIAL_COLUMNS = ['geom', 'geom_simplified'];

    protected $fillable = [
        'ubigeo',
        'name',
        'province',
        'department',
        'population',
        'area_km2',
    ];

    protected $hidden = self::SPATIAL_COLUMNS;

    protected $casts = [
        'population' => 'integer',
        'area_km2' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'ubigeo';
    }

    public function crimeStats(): HasMany
    {
        return $this->hasMany(CrimeStatMonthly::class);
    }

    /** Selecciona todas las columnas salvo las geometrías. */
    public function scopeWithoutGeometry(Builder $query): Builder
    {
        return $query->select([
            'id', 'ubigeo', 'department_code', 'province_code', 'name', 'province', 'department',
            'population', 'area_km2', 'created_at', 'updated_at',
        ]);
    }

    /** Distritos del ámbito configurado (todo el país si no hay prefijos). */
    public function scopeInScope(Builder $query): Builder
    {
        $prefixes = UbigeoScope::prefixes();
        if ($prefixes === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $q->orWhere('districts.ubigeo', 'like', $prefix.'%');
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_code', 'code');
    }

    public function provinceRegion(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }
}
