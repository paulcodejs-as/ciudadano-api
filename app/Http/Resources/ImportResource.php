<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Import */
class ImportResource extends JsonResource
{
    /** El log solo va en el detalle (puede ser largo). */
    public bool $withLog = false;

    public function withLog(): static
    {
        $this->withLog = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'file_name' => $this->file_name,
            'rows_read' => $this->rows_read,
            'rows_imported' => $this->rows_imported,
            'rows_skipped' => $this->rows_skipped,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_seconds' => $this->started_at && $this->finished_at
                ? $this->started_at->diffInSeconds($this->finished_at)
                : null,
            'log' => $this->when($this->withLog, $this->log),
        ];
    }
}
