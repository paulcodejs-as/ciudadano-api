<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Departamento (código INEI de 2 dígitos). Geometría simplificada solo para dibujar. */
class Department extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'population', 'area_km2'];

    protected $hidden = ['geom_simplified'];

    protected $casts = ['population' => 'integer', 'area_km2' => 'decimal:2'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'department_code', 'code');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'department_code', 'code');
    }
}
