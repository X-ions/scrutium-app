<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\SocialPlatform;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The filter set every dashboard query honours.
 *
 * Filters compose: a date range plus any combination of platform, account,
 * content type and campaign. They are value objects, not raw query parameters,
 * so a controller cannot accidentally leave one un-honoured — every read in
 * {@see AnalyticsQueryService} goes through {@see AnalyticsQueryService::scoped()}.
 */
final readonly class AnalyticsFilters
{
    /**
     * @param  list<SocialPlatform>  $platforms
     * @param  list<int>  $accountIds
     * @param  list<string>  $contentTypes
     * @param  list<int>  $campaignIds
     */
    public function __construct(
        public DateTimeInterface $from,
        public DateTimeInterface $to,
        public array $platforms = [],
        public array $accountIds = [],
        public array $contentTypes = [],
        public array $campaignIds = [],
    ) {
        if ($this->from->getTimestamp() > $this->to->getTimestamp()) {
            throw new InvalidArgumentException('AnalyticsFilters::from must not be after ::to.');
        }
    }

    public static function forRange(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $platforms = [],
        array $accountIds = [],
        array $contentTypes = [],
        array $campaignIds = [],
    ): self {
        return new self($from, $to, self::platforms($platforms), self::ints($accountIds), self::strings($contentTypes), self::ints($campaignIds));
    }

    /**
     * @param  array<int, array<int, mixed>>  $overrides
     */
    public static function make(array $overrides = []): self
    {
        $to = self::date($overrides['to'] ?? 'now') ?? new DateTimeImmutable;
        $from = self::date($overrides['from'] ?? '-29 days') ?? $to->modify('-29 days');

        return new self(
            $from,
            $to,
            self::platforms((array) ($overrides['platforms'] ?? $overrides['platform'] ?? [])),
            self::ints((array) ($overrides['account_ids'] ?? $overrides['account_id'] ?? [])),
            self::strings((array) ($overrides['content_types'] ?? $overrides['content_type'] ?? [])),
            self::ints((array) ($overrides['campaign_ids'] ?? $overrides['campaign_id'] ?? [])),
        );
    }

    public function has(): bool
    {
        return $this->platforms !== [] || $this->accountIds !== [] || $this->contentTypes !== [] || $this->campaignIds !== [];
    }

    /**
     * Filters that only make sense against rows attached to a post. When one is
     * set, account-level rows cannot be attributed and are left out rather than
     * being counted as if they belonged to the filtered content.
     */
    public function requiresPostAttribution(): bool
    {
        return $this->contentTypes !== [] || $this->campaignIds !== [];
    }

    public function describe(): array
    {
        return [
            'from' => DateTimeImmutable::createFromInterface($this->from)->format(DateTimeInterface::ATOM),
            'to' => DateTimeImmutable::createFromInterface($this->to)->format(DateTimeInterface::ATOM),
            'platforms' => array_map(static fn (SocialPlatform $p): string => $p->value, $this->platforms),
            'account_ids' => $this->accountIds,
            'content_types' => $this->contentTypes,
            'campaign_ids' => $this->campaignIds,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<SocialPlatform>
     */
    private static function platforms(array $values): array
    {
        $platforms = [];

        foreach ($values as $value) {
            $value = $value instanceof SocialPlatform ? $value->value : (is_string($value) ? $value : null);

            if ($value === null) {
                continue;
            }

            $platform = SocialPlatform::tryFrom(strtolower($value));

            if ($platform !== null && ! in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = strtolower(trim($value));
            }
        }

        return array_values(array_unique($strings));
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
