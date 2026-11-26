<?php

namespace App\Http\Requests\Admin;

use App\Services\ImportService;
use App\Services\Importers\SidpolCsvImporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** "Denuncias Policiales" (CSV o Excel .xlsx) subido desde el panel o la API. */
class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        $maxKb = intdiv(app(ImportService::class)->uploadLimitBytes(), 1024);

        return [
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', "max:{$maxKb}"],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->has('file') || ! $this->file('file')) {
                return;
            }

            // Falla rápido si no es el archivo de SIDPOL, en vez de encolar algo que fallará.
            $error = app(SidpolCsvImporter::class)->headerError($this->file('file')->getRealPath());
            if ($error) {
                $v->errors()->add('file', $error);
            }
        });
    }

    public function messages(): array
    {
        $limitMb = round(app(ImportService::class)->uploadLimitBytes() / 1024 / 1024, 1);

        return [
            'file.required' => 'Elige el archivo CSV o Excel (.xlsx) de Denuncias Policiales.',
            'file.uploaded' => "El archivo no se pudo subir. ¿Supera el límite de {$limitMb} MB?",
            'file.extensions' => 'El archivo debe ser CSV o Excel (.xlsx).',
            'file.max' => "El archivo supera el límite de {$limitMb} MB.",
        ];
    }
}
