<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Canonical readings from one provider response, plus the provider keys that
 * could NOT be represented.
 *
 * `unmapped` is the honest ledger: a key that arrived from the platform but has
 * no canonical counterpart is reported here rather than being quietly dropped
 * or forced into a bucket whose definition it does not share.
 */
final readonly class NormalizedAnalytics
{
    /**
     * @param  list<NormalizedMetric>  $accountMetrics
     * @param  array<string, list<NormalizedMetric>>  $postMetrics
     * @param  list<string>  $unmapped
     */
    public function __construct(
        public array $accountMetrics = [],
        public array $postMetrics = [],
        public array $unmapped = [],
        public ?array $raw = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->accountMetrics === [] && $this->postMetrics === [];
    }

    public function metricCount(): int
    {
        return count($this->accountMetrics) + array_sum(array_map('count', $this->postMetrics));
    }

    public function types(): array
    {
        $types = array_map(static fn (NormalizedMetric $m): string => $m->type->value, $this->accountMetrics);

        foreach ($this->postMetrics as $samples) {
            foreach ($samples as $sample) {
                $types[] = $sample->type->value;
            }
        }

        return array_values(array_unique($types));
    }
}
