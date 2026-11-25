<?php

namespace App\Support;

/**
 * Filtros ya resueltos para las consultas de estadísticas: rango de meses
 * inclusivo, modalidad opcional y métrica.
 */
final class StatsFilters
{
    public const METRIC_RATE = 'rate';

    public const METRIC_TOTAL = 'total';

    public function __construct(
        public readonly int $fromYear,
        public readonly int $fromMonth,
        public readonly int $toYear,
        public readonly int $toMonth,
        public readonly ?int $crimeTypeId = null,
        public readonly ?string $crimeTypeSlug = null,
        public readonly string $metric = self::METRIC_RATE,
    ) {
    }

    public function fromKey(): int
    {
        return $this->fromYear * 100 + $this->fromMonth;
    }

    public function toKey(): int
    {
        return $this->toYear * 100 + $this->toMonth;
    }

    public function from(): string
    {
        return sprintf('%04d-%02d', $this->fromYear, $this->fromMonth);
    }

    public function to(): string
    {
        return sprintf('%04d-%02d', $this->toYear, $this->toMonth);
    }

    /** Copia con otra métrica. */
    public function withMetric(string $metric): self
    {
        return new self($this->fromYear, $this->fromMonth, $this->toYear, $this->toMonth,
            $this->crimeTypeId, $this->crimeTypeSlug, $metric);
    }

    public function cacheKey(): string
    {
        return implode(':', [$this->from(), $this->to(), $this->crimeTypeSlug ?? 'all', $this->metric]);
    }
}
