<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\District */
class DistrictResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ubigeo' => $this->ubigeo,
            'name' => $this->name,
            'province' => $this->province,
            'department' => $this->department,
            'population' => $this->population,
            'area_km2' => $this->area_km2 !== null ? (float) $this->area_km2 : null,
        ];
    }
}
