<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One normalised metric reading.
 *
 * A metric a platform does not report is absent, never zero-filled — see
 * `MetricType` vocabulary in SOCIAL-PROVIDERS.md.
 */
final readonly class MetricSample implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, string>  $dimensions
     */
    public function __construct(
        public string $metric,
        public float $value,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public ?string $granularity = null,
        public array $dimensions = [],
    ) {}

    public function withDimensions(array $dimensions): self
    {
        return new self(
            $this->metric,
            $this->value,
            $this->periodStart,
            $this->periodEnd,
            $this->granularity,
            $dimensions + $this->dimensions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'value' => $this->value,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'granularity' => $this->granularity,
            'dimensions' => $this->dimensions,
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
