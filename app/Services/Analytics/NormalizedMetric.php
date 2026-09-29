<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\MetricType;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * One canonical metric reading, ready to be written to `analytics_metrics`.
 *
 * `metricSubtype` carries the provider's own breakdown dimension (`organic`,
 * `paid`, `video`, `page`, …) so a total can always be taken apart again
 * instead of being flattened into one number.
 */
final readonly class NormalizedMetric
{
    public function __construct(
        public MetricType $type,
        public float $value,
        public ?string $metricSubtype = null,
        public ?DateTimeInterface $periodStart = null,
        public ?DateTimeInterface $periodEnd = null,
        public ?string $granularity = null,
        /** Provider key this reading was mapped from, kept for traceability. */
        public ?string $sourceKey = null,
    ) {}

    public function withPeriod(
        ?DateTimeInterface $start,
        ?DateTimeInterface $end,
    ): self {
        return new self(
            $this->type,
            $this->value,
            $this->metricSubtype,
            $start,
            $end,
            $this->granularity,
            $this->sourceKey,
        );
    }

    public function withSubtype(?string $subtype): self
    {
        return new self(
            $this->type,
            $this->value,
            $subtype,
            $this->periodStart,
            $this->periodEnd,
            $this->granularity,
            $this->sourceKey,
        );
    }

    /**
     * The bucket key used by the idempotent upsert. `null` becomes an explicit
     * sentinel because a nullable column is never equal to another null in a
     * SQL unique index — MySQL, PostgreSQL and SQLite all treat NULLs as
     * distinct, so an `INSERT ... ON CONFLICT` on these columns would happily
     * insert a second account-level row for the same period.
     */
    public function dedupeKey(): string
    {
        return implode('|', [
            $this->type->value,
            $this->metricSubtype ?? "\0",
            $this->periodStart !== null ? $this->periodStart->format(DateTimeInterface::ATOM) : "\0",
        ]);
    }

    public function startOrNull(): ?DateTimeImmutable
    {
        if ($this->periodStart === null) {
            return null;
        }

        return $this->periodStart instanceof DateTimeImmutable
            ? $this->periodStart
            : DateTimeImmutable::createFromInterface($this->periodStart);
    }

    public function endOrNull(): ?DateTimeImmutable
    {
        if ($this->periodEnd === null) {
            return null;
        }

        return $this->periodEnd instanceof DateTimeImmutable
            ? $this->periodEnd
            : DateTimeImmutable::createFromInterface($this->periodEnd);
    }
}
