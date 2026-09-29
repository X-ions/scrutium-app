<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\MetricType;
use App\Enums\SocialPlatform;
use App\Models\AnalyticsMetric;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\FollowerStats;
use App\Services\Social\Data\ProviderPost;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes provider readings into `analytics_metrics`.
 *
 * ## Why this is not a `DB::table()->upsert()`
 *
 * The table's unique key is
 * `(tenant_id, social_account_id, post_variant_id, provider, metric_type, metric_subtype, period_start)`
 * — and two of those columns are nullable. MySQL, SQLite and PostgreSQL < 15 all
 * treat NULL as distinct from every other NULL in a unique index, so a
 * record-based `upsert` cannot match the account-level rows
 * (`post_variant_id IS NULL`, `metric_subtype IS NULL`) that make up most of the
 * data and would insert a duplicate on every re-sync. Matching the key
 * explicitly, with `whereNull` for the nullable parts, is the only way to make
 * re-syncing a period UPDATE rather than duplicate — which is the whole
 * contract of this table.
 *
 * ## Absent is absent
 *
 * Only readings the provider actually returned are written. A platform that
 * does not report reach produces no reach row at all, so no downstream sum can
 * mistake a missing metric for a zero one.
 */
class AnalyticsIngestService
{
    public function __construct(
        private readonly MetricNormalizer $normalizer = new MetricNormalizer,
        private readonly RetentionPolicy $retention = new RetentionPolicy,
    ) {}

    /**
     * Ingest a full provider batch: account-level readings plus every per-post
     * reading keyed by `provider_post_id`.
     */
    public function ingestBatch(SocialAccount $account, AnalyticsBatch $batch, ?DateTimeInterface $retentionNow = null): IngestResult
    {
        $platform = $this->platformFor($account, $batch->provider);
        $normalized = $this->normalizer->normalizeBatch($platform, $batch);

        $result = $this->write($account, null, $normalized->accountMetrics, $normalized->raw, $retentionNow);

        foreach ($normalized->postMetrics as $providerPostId => $metrics) {
            $result = $result->merge(
                $this->ingestPostMetrics($account, $platform, (string) $providerPostId, $metrics, $retentionNow),
            );
        }

        return $result->merge(new IngestResult(unmappedKeys: $normalized->unmapped));
    }

    /**
     * Ingest readings for a single post. The variant is matched on
     * `provider_post_id`; a post the CMS never published is recorded at account
     * level under an `unattributed:` subtype so the reading is neither lost nor
     * silently attributed to a post that does not exist.
     *
     * @param  list<NormalizedMetric>|null  $metrics
     */
    public function ingestPostAnalytics(
        SocialAccount $account,
        ProviderPost $post,
        ?AnalyticsBatch $batch = null,
        ?DateTimeInterface $retentionNow = null,
    ): IngestResult {
        $platform = $this->platformFor($account, $post->provider);

        $metrics = null;
        $raw = null;
        $unmapped = [];

        if ($batch !== null) {
            $normalized = $this->normalizer->normalizeBatch($platform, $batch);
            $metrics = $normalized->postMetrics[$post->providerPostId] ?? null;
            $raw = $normalized->raw;
            $unmapped = $normalized->unmapped;

            if ($metrics === null && $post->providerPostId === '' && $normalized->postMetrics !== []) {
                $metrics = array_values($normalized->postMetrics)[0];
            }
        }

        $periodStart = $post->publishedAt ?? $post->createdAt;
        $result = $this->writeVariant($account, $platform, $post->providerPostId, $metrics, $raw, $periodStart, $retentionNow);

        return $result->merge(new IngestResult(unmappedKeys: $unmapped));
    }

    /**
     * Ingest the account's follower/audience totals.
     *
     * Follower counts are point-in-time gauges, not interval values, so they
     * are stamped with the provider's own `asOf` and carry the
     * `cumulative` subtype marker the aggregator recognises.
     */
    public function ingestAccountAnalytics(SocialAccount $account, FollowerStats $stats, ?DateTimeInterface $retentionNow = null): IngestResult
    {
        $platform = $this->platformFor($account, $stats->provider);
        $asOf = $stats->asOf !== null
            ? DateTimeImmutable::createFromInterface($stats->asOf)
            : new DateTimeImmutable;

        $metrics = [new NormalizedMetric(
            type: MetricType::Followers,
            value: (float) $stats->total,
            periodStart: $asOf,
            periodEnd: $asOf,
            sourceKey: 'followers',
        )];

        $raw = $this->normalizer->normalizeRaw($platform, $stats->raw, $asOf, $asOf);

        $result = $this->write($account, null, $metrics, $raw->raw, $retentionNow);

        if ($stats->change !== null) {
            $type = $stats->change >= 0 ? MetricType::FollowerGains : MetricType::FollowerLosses;

            $result = $result->merge($this->write($account, null, [new NormalizedMetric(
                type: $type,
                value: (float) abs($stats->change),
                periodStart: $asOf,
                periodEnd: $asOf,
                sourceKey: $type->value,
            )], $raw->raw, $retentionNow));
        }

        return $result;
    }

    /**
     * Ingest an un-normalised payload, e.g. one delivered by a webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingestRawPayload(
        SocialAccount $account,
        array $payload,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?DateTimeInterface $retentionNow = null,
    ): IngestResult {
        $platform = $this->platformFor($account, (string) $account->provider->value);
        $normalized = $this->normalizer->normalizeRaw($platform, $payload, $from, $to);

        $result = $this->write($account, null, $normalized->accountMetrics, $normalized->raw, $retentionNow);

        return $result->merge(new IngestResult(unmappedKeys: $normalized->unmapped));
    }

    /**
     * @param  list<NormalizedMetric>|null  $metrics
     */
    private function ingestPostMetrics(
        SocialAccount $account,
        SocialPlatform $platform,
        string $providerPostId,
        array $metrics,
        ?DateTimeInterface $retentionNow,
    ): IngestResult {
        return $this->writeVariant($account, $platform, $providerPostId, $metrics, null, null, $retentionNow);
    }

    /**
     * @param  list<NormalizedMetric>|null  $metrics
     * @param  array<string, mixed>|null  $raw
     */
    private function writeVariant(
        SocialAccount $account,
        SocialPlatform $platform,
        string $providerPostId,
        ?array $metrics,
        ?array $raw,
        ?DateTimeInterface $fallbackStart,
        ?DateTimeInterface $retentionNow,
    ): IngestResult {
        $variant = $providerPostId === '' ? null : $this->resolveVariant($account, $providerPostId);

        if ($variant !== null) {
            return $this->write($account, $variant, $metrics, $raw, $retentionNow, $fallbackStart, $platform);
        }

        if ($metrics === null || $metrics === []) {
            return new IngestResult;
        }

        $unattributed = array_map(
            fn (NormalizedMetric $metric): NormalizedMetric => $metric->withSubtype(
                $metric->metricSubtype ?? 'unattributed:post:'.$providerPostId,
            ),
            $metrics,
        );

        $result = $this->write($account, null, $unattributed, $raw, $retentionNow, $fallbackStart, $platform);

        return new IngestResult(
            inserted: $result->inserted,
            updated: $result->updated,
            skippedOutOfRetention: $result->skippedOutOfRetention,
            skippedDuplicate: $result->skippedDuplicate,
            unmatchedPostIds: $providerPostId === '' ? [] : [$providerPostId],
            unmappedKeys: $result->unmappedKeys,
        );
    }

    /**
     * @param  list<NormalizedMetric>|null  $metrics
     * @param  array<string, mixed>|null  $raw
     */
    private function write(
        SocialAccount $account,
        ?PostVariant $variant,
        ?array $metrics,
        ?array $raw,
        ?DateTimeInterface $retentionNow,
        ?DateTimeInterface $fallbackStart = null,
        ?SocialPlatform $platform = null,
    ): IngestResult {
        if ($metrics === null || $metrics === []) {
            return new IngestResult;
        }

        $platform ??= $account->provider;
        $cutoff = $this->retention->cutoff($account, $retentionNow);

        $rows = [];
        $skippedRetention = 0;
        $now = new DateTimeImmutable;

        foreach ($metrics as $metric) {
            $start = $metric->startOrNull()
                ?? ($fallbackStart !== null ? DateTimeImmutable::createFromInterface($fallbackStart) : $now);

            if ($start < $cutoff) {
                $skippedRetention++;

                continue;
            }

            $end = $metric->endOrNull() ?? $start;
            $key = $this->composeKey(
                (int) $account->tenant_id,
                (int) $account->getKey(),
                $variant?->getKey() === null ? null : (int) $variant->getKey(),
                $platform->value,
                $metric->type->value,
                $metric->metricSubtype,
                $start,
            );

            $rows[$key] = [
                'tenant_id' => (int) $account->tenant_id,
                'social_account_id' => (int) $account->getKey(),
                'post_variant_id' => $variant?->getKey() === null ? null : (int) $variant->getKey(),
                'provider' => $platform->value,
                'metric_type' => $metric->type->value,
                'metric_subtype' => $metric->metricSubtype,
                'period_start' => $start,
                'period_end' => $end < $start ? $start : $end,
                'value' => (int) round($metric->value),
                'raw_response' => $raw,
                'recorded_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return new IngestResult(skippedOutOfRetention: $skippedRetention);
        }

        $persisted = $this->persist($account, $rows);

        return new IngestResult(
            inserted: $persisted['inserted'],
            updated: $persisted['updated'],
            skippedOutOfRetention: $skippedRetention,
        );
    }

    /**
     * Update-or-insert each row by explicit key match.
     *
     * @param  array<string, array<string, mixed>>  $rows  keyed by the composite identity
     * @return array{inserted: int, updated: int}
     */
    private function persist(SocialAccount $account, array $rows): array
    {
        $existing = $this->findExisting($account, array_values($rows));

        $inserted = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $existing, &$inserted, &$updated): void {
            foreach ($rows as $key => $row) {
                $match = $existing[$key] ?? null;

                $attributes = [
                    'period_end' => $row['period_end'],
                    'value' => $row['value'],
                    'raw_response' => $row['raw_response'],
                    'recorded_at' => $row['recorded_at'],
                ];

                if ($match instanceof AnalyticsMetric) {
                    if ($this->unchanged($match, $attributes)) {
                        continue;
                    }

                    $match->forceFill($attributes)->save();

                    $updated++;

                    continue;
                }

                $model = new AnalyticsMetric;
                $model->forceFill($row);
                $model->save();

                $inserted++;
            }
        });

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, AnalyticsMetric>
     */
    private function findExisting(SocialAccount $account, array $rows): array
    {
        $types = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['metric_type'],
            $rows,
        )));

        $periods = array_values(array_unique(array_map(
            static fn (array $row): string => CarbonImmutable::parse($row['period_start'])->toDateTimeString(),
            $rows,
        )));

        $candidates = AnalyticsMetric::query()
            ->where('social_account_id', $account->getKey())
            ->whereIn('metric_type', $types)
            ->whereIn('period_start', $periods)
            ->get()
            ->all();

        $found = [];

        foreach ($candidates as $candidate) {
            $found[$this->modelKey($candidate)] = $candidate;
        }

        return $found;
    }

    private function modelKey(AnalyticsMetric $metric): string
    {
        return $this->composeKey(
            (int) $metric->tenant_id,
            (int) $metric->social_account_id,
            $metric->post_variant_id === null ? null : (int) $metric->post_variant_id,
            (string) $metric->provider->value,
            (string) $metric->metric_type->value,
            $metric->metric_subtype,
            $metric->period_start,
        );
    }

    private function composeKey(
        int $tenantId,
        int $socialAccountId,
        ?int $postVariantId,
        string $provider,
        string $metricType,
        ?string $metricSubtype,
        mixed $periodStart,
    ): string {
        $start = $periodStart instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($periodStart)
            : CarbonImmutable::parse((string) $periodStart);

        return implode('|', [
            $tenantId,
            $socialAccountId,
            $postVariantId ?? "\0",
            $provider,
            $metricType,
            $metricSubtype ?? "\0",
            $start->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function unchanged(AnalyticsMetric $metric, array $attributes): bool
    {
        if ((int) $metric->value !== (int) $attributes['value']) {
            return false;
        }

        $raw = $metric->raw_response;
        $next = $attributes['raw_response'];

        if ($raw === null && $next === null) {
            return true;
        }

        return json_encode($raw) === json_encode($next);
    }

    private function resolveVariant(SocialAccount $account, string $providerPostId): ?PostVariant
    {
        return PostVariant::query()
            ->where('social_account_id', $account->getKey())
            ->where('provider_post_id', $providerPostId)
            ->first();
    }

    private function platformFor(SocialAccount $account, string $provider): SocialPlatform
    {
        $resolved = SocialPlatform::tryFrom($provider);

        if ($resolved !== null) {
            return $resolved;
        }

        try {
            return $account->provider;
        } catch (Throwable) {
            return SocialPlatform::Facebook;
        }
    }
}
