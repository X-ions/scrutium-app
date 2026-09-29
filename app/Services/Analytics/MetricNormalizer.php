<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\MetricType;
use App\Enums\SocialPlatform;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\MetricSample;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Maps raw provider payloads onto the canonical {@see MetricType} vocabulary.
 *
 * Two rules govern everything here.
 *
 * 1. **A metric the platform did not report is absent, never zero.** The
 *    normaliser only ever emits a reading for a key that is physically present
 *    in the response. It never fills a gap in the "expected" metric list, and
 *    `MetricType::tryFrom()` returning null drops the reading rather than
 *    defaulting it to a value that would then be summed into a dashboard
 *    total. A reported `0` is kept — that is a real measurement.
 * 2. **The provider's own breakdown is preserved.** `metric_subtype` carries
 *    `organic`/`paid`, `page`/`video`, `follower`/`non-follower` and similar
 *    qualifiers so a total can be taken apart again. A subtype never changes
 *    which canonical bucket a reading belongs to.
 *
 * ## Vocabulary gap
 *
 * SOCIAL-PROVIDERS.md names a wider vocabulary than {@see MetricType} currently
 * declares. The keys below are recognised; those with no `MetricType` case are
 * returned in {@see NormalizedAnalytics::$unmapped} and deliberately NOT stored,
 * because writing an average view duration in seconds into a metric whose unit
 * is minutes — or a reaction count into a likes counter — would corrupt every
 * total it is later summed into. Adding the missing enum cases is all that is
 * needed for them to start persisting.
 */
final class MetricNormalizer
{
    /**
     * Keys with no `MetricType` case today. Kept in one place so the gap is
     * visible and testable rather than implied by an empty branch.
     *
     * @var list<string>
     */
    public const UNSTORED_VOCABULARY = [
        'reactions',
        'avg_view_duration_seconds',
        'completion_rate',
    ];

    /**
     * Dimension keys a provider may use to qualify a reading.
     *
     * @var list<string>
     */
    private const SUBTYPE_DIMENSIONS = [
        'metric_subtype',
        'subtype',
        'breakdown',
        'surface',
        'content_source',
        'distribution',
        'media_type',
        'story_type',
        'organic_type',
    ];

    /**
     * Provider keys that are recognised as belonging to the wider documented
     * vocabulary but have no `MetricType` case to live in. They are reported in
     * {@see NormalizedAnalytics::$unmapped} and never written, because storing
     * seconds into a minutes metric — or reactions into a likes counter — would
     * corrupt every total the reading is later summed into.
     *
     * @var list<string>
     */
    private const KNOWN_UNSTORED = [
        'reactions',
        'reactioncount',
        'reactionscount',
        'reactionsbytypetotal',
        'postreactionsbytypetotal',
        'avgviewduration',
        'avgviewdurationseconds',
        'averageviewduration',
        'averageviewdurationseconds',
        'completionrate',
        'averageviewpercentage',
    ];

    /**
     * Provider key => canonical metric. Keys are compared case-insensitively
     * after stripping `_`, `-` and spaces, so `post_impressions`,
     * `postImpressions` and `POST-IMPRESSIONS` all land on the same bucket.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        // Views
        'views' => 'views',
        'view' => 'views',
        'viewcount' => 'views',
        'viewscount' => 'views',
        'contentviews' => 'views',
        'postvideoviews' => 'views',
        'videoviews' => 'views',
        'videoviewcount' => 'views',
        'playcount' => 'views',
        'plays' => 'views',
        'organicvideoviews' => 'views',
        'uniquepageviews' => 'views',

        // Impressions
        'impressions' => 'impressions',
        'impressioncount' => 'impressions',
        'postimpressions' => 'impressions',
        'postimpressionscount' => 'impressions',
        'totalimpressions' => 'impressions',
        'organicimpressions' => 'impressions',
        'paidimpressions' => 'impressions',

        // Reach
        'reach' => 'reach',
        'reachcount' => 'reach',
        'postimpressionsunique' => 'reach',
        'uniqueimpressions' => 'reach',
        'uniqueimpressionscount' => 'reach',
        'accountsreached' => 'reach',
        'uniqueprofileviews' => 'reach',

        // Engagement components
        'likes' => 'likes',
        'likecount' => 'likes',
        'likescount' => 'likes',
        'likedcount' => 'likes',
        'comments' => 'comments',
        'commentcount' => 'comments',
        'commentscount' => 'comments',
        'shares' => 'shares',
        'sharecount' => 'shares',
        'sharescount' => 'shares',
        'retweets' => 'shares',
        'saves' => 'saves',
        'savecount' => 'saves',
        'savescount' => 'saves',
        'bookmarks' => 'saves',
        'clicks' => 'clicks',        'clickcount' => 'clicks',
        'linkclicks' => 'clicks',
        'postclicks' => 'clicks',
        'mentions' => 'mentions',
        'mentioncount' => 'mentions',

        // Totals the provider computed for us
        'engagements' => 'engagement',
        'engagement' => 'engagement',
        'totalengagement' => 'engagement',
        'totalengagements' => 'engagement',
        'engagementscount' => 'engagement',
        'postengagements' => 'engagement',
        'engagementcount' => 'engagement',

        // Video consumption
        'watchtime' => 'watch_time',
        'watchtimeminutes' => 'watch_time',
        'minuteswatched' => 'watch_time',
        'totalwatchtime' => 'watch_time',
        'estimatedminuteswatched' => 'watch_time',

        // Audience
        'followers' => 'followers',
        'followercount' => 'followers',
        'followerscount' => 'followers',
        'fans' => 'followers',
        'fancount' => 'followers',
        'subscribers' => 'followers',
        'subscribercount' => 'followers',
        'audience' => 'followers',
        'audiencecount' => 'followers',
        'followersgained' => 'follower_gains',
        'followergains' => 'follower_gains',
        'newfollowers' => 'follower_gains',
        'gainedfollowers' => 'follower_gains',
        'follows' => 'follower_gains',
        'followerslost' => 'follower_losses',
        'followerlosses' => 'follower_losses',
        'lostfollowers' => 'follower_losses',
        'unfollows' => 'follower_losses',
        'profilevisits' => 'profile_visits',
        'profileviews' => 'profile_visits',
    ];

    public function __construct(
        private readonly RawPayloadSanitizer $sanitizer = new RawPayloadSanitizer,
    ) {}

    /**
     * Normalise a provider `AnalyticsBatch` for one account.
     */
    public function normalizeBatch(SocialPlatform $platform, AnalyticsBatch $batch): NormalizedAnalytics
    {
        $fallbackStart = $this->toImmutable($batch->periodStart);
        $fallbackEnd = $this->toImmutable($batch->periodEnd);

        $account = [];
        $unmapped = [];

        foreach ($batch->accountMetrics as $sample) {
            $metric = $this->normalizeSample($sample, $fallbackStart, $fallbackEnd, $unmapped);

            if ($metric !== null) {
                $account[] = $metric;
            }
        }

        $post = [];

        foreach ($batch->postMetrics as $providerPostId => $samples) {
            $collected = [];

            foreach ($samples as $sample) {
                $metric = $this->normalizeSample($sample, $fallbackStart, $fallbackEnd, $unmapped);

                if ($metric !== null) {
                    $collected[] = $metric;
                }
            }

            if ($collected !== []) {
                $post[(string) $providerPostId] = $collected;
            }
        }

        return new NormalizedAnalytics(
            accountMetrics: $account,
            postMetrics: $post,
            unmapped: array_values(array_unique($unmapped)),
            raw: $this->sanitizer->sanitize($batch->raw, $platform->value),
        );
    }

    /**
     * Normalise a single provider sample. Returns null — never a zero — when
     * the sample cannot be represented canonically.
     *
     * @param  list<string>  $unmapped
     */
    public function normalizeSample(
        MetricSample $sample,
        ?DateTimeInterface $fallbackStart = null,
        ?DateTimeInterface $fallbackEnd = null,
        array &$unmapped = [],
    ): ?NormalizedMetric {
        $type = $this->resolveType($sample->metric, $unmapped);

        if ($type === null) {
            return null;
        }

        $value = $this->numeric($sample->value);

        if ($value === null) {
            return null;
        }

        $start = $this->toImmutable($sample->periodStart) ?? $fallbackStart;
        $end = $this->toImmutable($sample->periodEnd) ?? $start ?? $fallbackEnd;

        return new NormalizedMetric(
            type: $type,
            value: $value,
            metricSubtype: $this->subtypeFrom($sample->dimensions),
            periodStart: $start,
            periodEnd: $end,
            granularity: $sample->granularity,
            sourceKey: $sample->metric,
        );
    }

    /**
     * Best-effort normalisation of an un-normalised provider payload, used when
     * a provider (or a webhook carrying a metrics payload) hands us plain JSON.
     *
     * Only physically present numeric leaves become readings. Nothing is
     * inferred from a sibling field, and an absent metric produces no key.
     *
     * @param  array<string, mixed>  $payload
     */
    public function normalizeRaw(SocialPlatform $platform, array $payload, ?DateTimeInterface $fallbackStart = null, ?DateTimeInterface $fallbackEnd = null): NormalizedAnalytics
    {
        $unmapped = [];
        $metrics = $this->read($payload, $fallbackStart, $fallbackEnd, $unmapped, 0);

        return new NormalizedAnalytics(
            accountMetrics: $metrics,
            unmapped: array_values(array_unique($unmapped)),
            raw: $this->sanitizer->sanitize($payload, $platform->value),
        );
    }

    /**
     * Canonical type for a provider key, or null when it cannot be stored.
     *
     * @param  list<string>  $unmapped
     */
    public function resolveType(string $providerKey, array &$unmapped = []): ?MetricType
    {
        $key = $this->key($providerKey);

        $value = self::ALIASES[$key] ?? null;

        if ($value === null) {
            $unmapped[] = $providerKey;

            return null;
        }

        $type = MetricType::tryFrom($value);

        if ($type === null) {
            $unmapped[] = $providerKey;
        }

        return $type;
    }

    /**
     * Every canonical metric this normaliser can produce.
     *
     * @return list<MetricType>
     */
    public function supportedTypes(): array
    {
        $types = array_map(
            static fn (string $value): ?MetricType => MetricType::tryFrom($value),
            array_values(array_unique(array_values(self::ALIASES))),
        );

        return array_values(array_filter($types));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $unmapped
     * @return list<NormalizedMetric>
     */
    private function read(array $node, ?DateTimeInterface $start, ?DateTimeInterface $end, array &$unmapped, int $depth): array
    {
        if ($depth > 5) {
            return [];
        }

        $metrics = $this->readNamedSeries($node, $start, $end, $unmapped);

        foreach ($node as $key => $value) {
            $type = $this->resolveType((string) $key, $unmapped);
            if (is_array($value) && $type === null) {
                $metrics = [...$metrics, ...$this->read($value, $start, $end, $unmapped, $depth + 1)];

                continue;
            }

            if ($type === null) {
                continue;
            }

            foreach ($this->readings($value, $type, (string) $key, $start, $end) as $reading) {
                $metrics[] = $reading;
            }
        }

        return $metrics;
    }

    /**
     * The shape Meta and X use: a series object that names its own metric
     * (`{name: 'post_impressions', values: [...]}` or `{name, period, value}`).
     * Without this, a whole insights payload would parse to nothing.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $unmapped
     * @return list<NormalizedMetric>
     */
    private function readNamedSeries(array $node, ?DateTimeInterface $start, ?DateTimeInterface $end, array &$unmapped): array
    {
        $name = $node['name'] ?? $node['metric'] ?? null;

        if (! is_string($name) || $name === '') {
            return [];
        }

        $type = $this->resolveType($name, $unmapped);

        if ($type === null) {
            return [];
        }

        $metrics = [];

        foreach ((array) ($node['values'] ?? []) as $value) {
            if (! is_array($value)) {
                continue;
            }

            $number = $this->numeric($value['value'] ?? null);

            if ($number === null) {
                continue;
            }

            $pointStart = $this->toImmutable($value['period_start'] ?? $value['start_time'] ?? $value['end_time'] ?? $value['date'] ?? null) ?? $start;

            $metrics[] = new NormalizedMetric(
                type: $type,
                value: $number,
                metricSubtype: $this->subtypeFrom($value),
                periodStart: $pointStart,
                periodEnd: $this->toImmutable($value['period_end'] ?? $value['end_time'] ?? null) ?? $pointStart,
                sourceKey: $name,
            );
        }

        $scalar = $this->numeric($node['value'] ?? null);

        if ($scalar !== null && $metrics === []) {
            $pointStart = $this->toImmutable($node['period'] ?? $node['date'] ?? null) ?? $start;

            $metrics[] = new NormalizedMetric(
                type: $type,
                value: $scalar,
                metricSubtype: $this->subtypeFrom($node),
                periodStart: $pointStart,
                periodEnd: $pointStart,
                sourceKey: $name,
            );
        }

        return $metrics;
    }

    /**
     * @return list<NormalizedMetric>
     */
    private function readings(mixed $value, MetricType $type, string $sourceKey, ?DateTimeInterface $start, ?DateTimeInterface $end, int $depth = 0): array
    {
        $scalar = $this->numeric($value);

        if ($scalar !== null) {
            return [new NormalizedMetric(
                type: $type,
                value: $scalar,
                periodStart: $start,
                periodEnd: $end,
                sourceKey: $sourceKey,
            )];
        }

        if (! is_array($value)) {
            return [];
        }

        $metrics = [];

        foreach ($value as $entryKey => $entry) {
            $subtype = $this->subtypeFrom(is_array($entry) ? $entry : []);
            $number = $this->numeric(is_array($entry) ? ($entry['value'] ?? $entry['count'] ?? null) : $entry);

            if ($number !== null) {
                $metrics[] = new NormalizedMetric(
                    type: $type,
                    value: $number,
                    metricSubtype: $subtype ?? (is_string($entryKey) && ! ctype_digit($entryKey) ? $this->key($entryKey) : null),
                    periodStart: is_array($entry) ? ($this->toImmutable($entry['period_start'] ?? $entry['date'] ?? $entry['end_time'] ?? null) ?? $start) : $start,
                    periodEnd: is_array($entry) ? ($this->toImmutable($entry['period_end'] ?? $entry['end_time'] ?? null) ?? $end) : $end,
                    sourceKey: $sourceKey,
                );

                continue;
            }

            if (is_array($entry) && $depth < 5) {
                $metrics = [...$metrics, ...$this->readings($entry, $type, $sourceKey, $start, $end, $depth + 1)];
            }
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $dimensions
     */
    private function subtypeFrom(array $dimensions): ?string
    {
        foreach (self::SUBTYPE_DIMENSIONS as $name) {
            if (! array_key_exists($name, $dimensions)) {
                continue;
            }

            $value = $dimensions[$name];

            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }

            if (is_array($value)) {
                $inner = $value['value'] ?? $value['type'] ?? $value['name'] ?? null;

                if (is_string($inner) && trim($inner) !== '') {
                    return strtolower(trim($inner));
                }

                if ($inner !== null) {
                    return strtolower($this->key((string) $inner));
                }
            }
        }

        return null;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * Collapse a provider key to its lookup form: lowercase with every
     * separator removed, so `post_impressions`, `postImpressions` and
     * `POST-IMPRESSIONS` all reach the same alias.
     */
    private function key(string $value): string
    {
        return strtolower(str_replace([' ', '-', '.', '/', '_', ':'], '', trim($value)));
    }

    private function toImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            return (new DateTimeImmutable)->setTimestamp($value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
