<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Fila del ranking (array que devuelve CrimeStatsService::rankings). */
class RankingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'rank' => $r['rank'],
            'code' => $r['code'],
            'level' => $r['level'],
            'name' => $r['name'],
            // Solo distritos: ubigeo y provincia.
            'ubigeo' => $this->when(isset($r['ubigeo']), $r['ubigeo'] ?? null),
            'province' => $this->when(isset($r['province']), $r['province'] ?? null),
            'population' => $r['population'],
            'total' => $r['total'],
            'rate' => $r['rate'],
            'previous_total' => $r['previous_total'],
            'change_pct' => $r['change_pct'],
            'value' => $r['value'],
        ];
    }
}
