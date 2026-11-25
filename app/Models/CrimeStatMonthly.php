<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conteo mensual de denuncias registradas por distrito y modalidad. */
class CrimeStatMonthly extends Model
{
    use HasFactory;

    protected $table = 'crime_stats_monthly';

    protected $fillable = [
        'district_id',
        'crime_type_id',
        'data_source_id',
        'year',
        'month',
        'count',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'count' => 'integer',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function crimeType(): BelongsTo
    {
        return $this->belongsTo(CrimeType::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** Filtra por rango de meses inclusivo, con fechas en formato YYYY-MM. */
    public function scopeBetweenMonths(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            [$y, $m] = array_map('intval', explode('-', $from));
            $query->whereRaw('(year * 100 + month) >= ?', [$y * 100 + $m]);
        }
        if ($to) {
            [$y, $m] = array_map('intval', explode('-', $to));
            $query->whereRaw('(year * 100 + month) <= ?', [$y * 100 + $m]);
        }

        return $query;
    }
}
