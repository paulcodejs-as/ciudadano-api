<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Provincia (código INEI de 4 dígitos). Geometría simplificada solo para dibujar. */
class Province extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'department_code', 'name', 'population', 'area_km2'];

    protected $hidden = ['geom_simplified'];

    protected $casts = ['population' => 'integer', 'area_km2' => 'decimal:2'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_code', 'code');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'province_code', 'code');
    }
}
