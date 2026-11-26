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

    /** % de cambio frente a los mismos meses del año anterior. */
    public const METRIC_CHANGE = 'change';

    public const METRICS = [self::METRIC_RATE, self::METRIC_TOTAL, self::METRIC_CHANGE];

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

    /** Los mismos meses, 12 meses antes (comparación interanual: respeta la estacionalidad). */
    public function previousYear(): self
    {
        return new self($this->fromYear - 1, $this->fromMonth, $this->toYear - 1, $this->toMonth,
            $this->crimeTypeId, $this->crimeTypeSlug, $this->metric);
    }

    /** Copia sin filtro de modalidad. */
    public function withoutCrimeType(): self
    {
        return new self($this->fromYear, $this->fromMonth, $this->toYear, $this->toMonth, null, null, $this->metric);
    }

    public function cacheKey(): string
    {
        return implode(':', [$this->from(), $this->to(), $this->crimeTypeSlug ?? 'all', $this->metric]);
    }
}
