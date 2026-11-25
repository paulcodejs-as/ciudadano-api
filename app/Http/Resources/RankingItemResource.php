<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Fila del ranking (array que devuelve CrimeStatsService::rankings). */
class RankingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'rank' => $this->resource['rank'],
            'ubigeo' => $this->resource['ubigeo'],
            'name' => $this->resource['name'],
            'province' => $this->resource['province'],
            'population' => $this->resource['population'],
            'total' => $this->resource['total'],
            'rate' => $this->resource['rate'],
            'value' => $this->resource['value'],
        ];
    }
}
