<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Analytics request window, granularity, and optional post/metric filters.
 */
final readonly class AnalyticsQuery implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $postIds
     * @param  list<string>  $metrics
     * @param  list<string>  $breakdowns
     */
    public function __construct(
        public DateTimeInterface $from,
        public DateTimeInterface $to,
        public string $granularity = 'day',
        public array $postIds = [],
        public array $metrics = [],
        public array $breakdowns = [],
        public string $timezone = 'UTC',
    ) {
        if ($from->getTimestamp() > $to->getTimestamp()) {
            throw new \InvalidArgumentException('AnalyticsQuery::from must not be after ::to.');
        }
    }

    public static function forWindow(int $days, string $granularity = 'day', string $timezone = 'UTC'): self
    {
        $to = new DateTimeImmutable('now', new \DateTimeZone($timezone));

        return new self(
            $to->modify(sprintf('-%d days', $days)),
            $to,
            $granularity,
        );
    }

    public function dayCount(): int
    {
        return (int) ceil(($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400);
    }

    public function withPostIds(array $postIds): self
    {
        return new self(
            $this->from,
            $this->to,
            $this->granularity,
            array_values($postIds),
            $this->metrics,
            $this->breakdowns,
            $this->timezone,
        );
    }

    public function withMetrics(array $metrics): self
    {
        return new self(
            $this->from,
            $this->to,
            $this->granularity,
            $this->postIds,
            array_values($metrics),
            $this->breakdowns,
            $this->timezone,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format(DateTimeInterface::ATOM),
            'to' => $this->to->format(DateTimeInterface::ATOM),
            'granularity' => $this->granularity,
            'post_ids' => $this->postIds,
            'metrics' => $this->metrics,
            'breakdowns' => $this->breakdowns,
            'timezone' => $this->timezone,
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
