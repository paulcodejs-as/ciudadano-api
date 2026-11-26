<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** lat/lng dentro del rectángulo del Perú (config ciudadano.bounds). */
class LocateDistrictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        [$latMin, $latMax] = config('ciudadano.bounds.lat');
        [$lngMin, $lngMax] = config('ciudadano.bounds.lng');

        return [
            'lat' => ['required', 'numeric', "between:{$latMin},{$latMax}"],
            'lng' => ['required', 'numeric', "between:{$lngMin},{$lngMax}"],
        ];
    }

    public function messages(): array
    {
        return [
            'lat.between' => 'La latitud está fuera del Perú.',
            'lng.between' => 'La longitud está fuera del Perú.',
        ];
    }
}
