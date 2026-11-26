<?php

namespace App\Http\Requests;

use App\Support\StatsFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filtros comunes de /map/districts, /rankings y /districts/{ubigeo}:
 * year=2024 | from=YYYY-MM&to=YYYY-MM, crime_type=slug, metric=rate|total.
 */
class StatsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $firstYear = (int) config('ciudadano.first_year', 2018);
        $lastYear = now()->year + 1;

        return [
            'year' => ['nullable', 'integer', "between:{$firstYear},{$lastYear}", 'prohibits:from,to'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'crime_type' => ['nullable', 'string', Rule::exists('crime_types', 'slug')],
            'metric' => ['nullable', Rule::in(StatsFilters::METRICS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $from = $this->input('from');
            $to = $this->input('to');
            $min = sprintf('%04d-01', (int) config('ciudadano.first_year', 2018));

            // Y-m se compara bien como texto.
            if (is_string($from) && is_string($to) && ! $v->errors()->hasAny(['from', 'to']) && $from > $to) {
                $v->errors()->add('to', 'El mes final debe ser igual o posterior al mes inicial.');
            }
            if (is_string($from) && ! $v->errors()->has('from') && $from < $min) {
                $v->errors()->add('from', "No hay datos antes de {$min}.");
            }
        });
    }

    public function messages(): array
    {
        return [
            'year.prohibits' => 'Usa year o bien from/to, no ambos.',
            'from.date_format' => 'El mes inicial debe tener el formato AAAA-MM.',
            'to.date_format' => 'El mes final debe tener el formato AAAA-MM.',
            'crime_type.exists' => 'La modalidad no existe.',
            'metric.in' => 'La métrica debe ser rate, total o change.',
        ];
    }
}
