<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Departamento, provincia o distrito para las listas de los filtros (sin geometría). */
class RegionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'population' => $this->population !== null ? (int) $this->population : null,
        ];
    }
}
