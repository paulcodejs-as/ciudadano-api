<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrimeType extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'color'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function crimeStats(): HasMany
    {
        return $this->hasMany(CrimeStatMonthly::class);
    }
}
