<?php

namespace App\Http\Requests;

/** /districts/compare?ubigeos=150122,150131 + los filtros de StatsFilterRequest. */
class CompareDistrictsRequest extends StatsFilterRequest
{
    public const MAX_DISTRICTS = 4;

    public function rules(): array
    {
        return parent::rules() + [
            'ubigeos' => ['required', 'string', 'regex:/^\d{6}(,\d{6}){1,'.(self::MAX_DISTRICTS - 1).'}$/'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'ubigeos.required' => 'Indica los ubigeos a comparar, separados por coma.',
            'ubigeos.regex' => 'Envía entre 2 y '.self::MAX_DISTRICTS.' ubigeos de 6 dígitos separados por coma.',
        ];
    }

    /** @return list<string> ubigeos sin repetir, en el orden enviado */
    public function ubigeos(): array
    {
        return array_values(array_unique(explode(',', (string) $this->validated('ubigeos'))));
    }
}
