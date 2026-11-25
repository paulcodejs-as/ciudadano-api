<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    use HasFactory;

    public const SIDPOL = 'sidpol';

    protected $fillable = ['slug', 'name', 'url', 'cutoff_date', 'notes'];

    protected $casts = [
        'cutoff_date' => 'date',
    ];

    public function crimeStats(): HasMany
    {
        return $this->hasMany(CrimeStatMonthly::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }
}
