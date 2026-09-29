<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Metrics for one account over a requested window, split into account-level
 * totals and per-post readings.
 */
final readonly class AnalyticsBatch implements Arrayable, JsonSerializable
{
    /**
     * @param  list<MetricSample>  $accountMetrics
     * @param  array<string, list<MetricSample>>  $postMetrics
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public DateTimeInterface $periodStart,
        public DateTimeInterface $periodEnd,
        public array $accountMetrics = [],
        public array $postMetrics = [],
        public ?string $granularity = null,
        public array $raw = [],
    ) {}

    /**
     * @return list<string>
     */
    public function availableMetrics(): array
    {
        $names = array_map(
            static fn (MetricSample $sample): string => $sample->metric,
            $this->accountMetrics,
        );

        foreach ($this->postMetrics as $samples) {
            foreach ($samples as $sample) {
                $names[] = $sample->metric;
            }
        }

        return array_values(array_unique($names));
    }

    public function totalFor(string $metric): ?float
    {
        foreach ($this->accountMetrics as $sample) {
            if ($sample->metric === $metric) {
                return $sample->value;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->accountMetrics === [] && $this->postMetrics === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'period_start' => $this->periodStart->format(DateTimeInterface::ATOM),
            'period_end' => $this->periodEnd->format(DateTimeInterface::ATOM),
            'granularity' => $this->granularity,
            'account_metrics' => array_map(
                static fn (MetricSample $s): array => $s->toArray(),
                $this->accountMetrics,
            ),
            'post_metrics' => array_map(
                static fn (array $samples): array => array_map(
                    static fn (MetricSample $s): array => $s->toArray(),
                    $samples,
                ),
                $this->postMetrics,
            ),
            'available_metrics' => $this->availableMetrics(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
