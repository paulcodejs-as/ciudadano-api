<?php

namespace App\Http\Requests;

use App\Support\Level;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filtros de /map/regions y /rankings: los de StatsFilterRequest + nivel y padre.
 * level=department|province|district, parent=código de departamento (2) o provincia (4).
 */
class RegionFilterRequest extends StatsFilterRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'level' => ['nullable', Rule::in(Level::ALL)],
            'parent' => ['nullable', 'regex:/^\d{2}(\d{2})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $v) {
            if ($v->errors()->hasAny(['level', 'parent'])) {
                return;
            }
            $parent = $this->input('parent');
            if (! $parent) {
                return;
            }

            if (strlen($parent) >= Level::length($this->level())) {
                $v->errors()->add('parent', 'El padre debe ser de un nivel superior al nivel pedido.');

                return;
            }

            $exists = strlen($parent) === 2
                ? DB::table('departments')->where('code', $parent)->exists()
                : DB::table('provinces')->where('code', $parent)->exists();
            if (! $exists) {
                $v->errors()->add('parent', 'El departamento o provincia no existe.');
            }
        });
    }

    public function messages(): array
    {
        return parent::messages() + [
            'level.in' => 'El nivel debe ser department, province o district.',
            'parent.regex' => 'El padre es un código de departamento (2 dígitos) o de provincia (4 dígitos).',
        ];
    }

    /** Nivel pedido; por defecto el hijo del padre, o departamentos si no hay padre. */
    public function level(?string $default = null): string
    {
        if ($this->filled('level')) {
            return (string) $this->input('level');
        }
        $parent = $this->input('parent');
        if ($parent && preg_match('/^\d{2}(\d{2})?$/', $parent)) {
            return Level::childOf(Level::ofCode($parent));
        }

        return $default ?? Level::DEPARTMENT;
    }

    public function parent(): ?string
    {
        return $this->validated('parent') ?: null;
    }

    /** Solo los filtros de estadísticas (sin level/parent). */
    public function statsInput(): array
    {
        return $this->safe()->except(['level', 'parent']);
    }
}
