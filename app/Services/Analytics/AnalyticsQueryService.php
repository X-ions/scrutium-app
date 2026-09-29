<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\MetricType;
use App\Enums\SocialPlatform;
use App\Models\AnalyticsMetric;
use App\Models\PostVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind the dashboard.
 *
 * Every read is a SQL aggregate over `analytics_metrics`; rows are never pulled
 * into PHP to be summed there. Three rules run through the whole class:
 *
 * - **Absence is absence.** A metric with no rows returns `null`, not `0`. A
 *   headline total reports which networks actually contributed.
 * - **Unlike metrics are never silently added.** Each response carries a
 *   `comparability` block from {@see MetricComparability} and, where relevant,
 *   a per-platform breakdown, so a number that mixes two platforms' different
 *   definitions is always labelled as such.
 * - **The engagement rate names its denominator.** `engagements / reach` when
 *   reach exists, otherwise `engagements / followers`, and the response says
 *   which was used.
 */
class AnalyticsQueryService
{
    public function __construct(
        private readonly MetricComparability $comparability = new MetricComparability,
    ) {}

    /**
     * Headline numbers for a filtered selection.
     *
     * @return array<string, mixed>
     */
    public function headlineTotals(AnalyticsFilters $filters): array
    {
        $matrix = $this->metricMatrix($filters);

        $perPlatform = [];
        $totals = [];
        $available = [];

        foreach ($matrix as $row) {
            $provider = (string) $row['provider'];
            $metric = (string) $row['metric_type'];
            $value = (int) round((float) $row['total']);

            $perPlatform[$provider][$metric] = $value;
            $totals[$metric] = ($totals[$metric] ?? 0) + $value;
            $available[$provider][] = $metric;
        }

        $followers = $this->followerTotals($filters);

        $totals['followers'] = $followers['current'];
        $totals['follower_growth'] = $followers['growth'];

        foreach ($this->availability($filters, [
            MetricType::Followers->value,
            MetricType::FollowerGains->value,
            MetricType::FollowerLosses->value,
        ]) as $provider => $types) {
            $available[$provider] = array_values(array_unique([...($available[$provider] ?? []), ...$types]));
        }

        $selected = $this->selectedPlatforms($filters, $available);

        $engagements = $this->engagements($totals);
        $rate = $this->engagementRate($engagements['value'], $totals, $followers['current']);

        $reportable = [
            MetricType::Reach->value,
            MetricType::Views->value,
            MetricType::Impressions->value,
            MetricType::Engagement->value,
            MetricType::Followers->value,
            MetricType::FollowerGains->value,
            MetricType::FollowerLosses->value,
        ];

        $comparability = $this->comparability->describe(
            $selected,
            $available,
            array_values(array_unique([...$reportable, ...array_keys($totals)])),
        );

        return [
            'filters' => $filters->describe(),
            'totals' => $totals,
            'per_platform' => $perPlatform,
            'reported_by' => $this->reportedBy($totals, $available, $selected),
            'engagements' => $engagements['value'],
            'engagement_basis' => $engagements['basis'],
            'engagement_rate' => $rate,
            'comparability' => $comparability,
            'warnings' => $comparability['warnings'],
        ];
    }

    /**
     * One metric over time, bucketed by hour/day/week/month.
     *
     * @return array<string, mixed>
     */
    public function metricSeries(AnalyticsFilters $filters, MetricType|string $metric, string $granularity = 'day'): array
    {
        $type = $metric instanceof MetricType ? $metric : (MetricType::tryFrom($metric) ?? MetricType::Views);

        $bucket = $this->bucketExpression($granularity, 'analytics_metrics.period_start');

        $rows = $this->scoped($filters)
            ->where('metric_type', $type->value)
            ->selectRaw($bucket.' as bucket')
            ->selectRaw('SUM(value) as total')
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->toBase()->get();

        $available = $this->availability($filters, [$type->value]);
        $selected = $this->selectedPlatforms($filters, $available);

        return [
            'metric' => $type->value,
            'label' => $type->label(),
            'granularity' => $granularity,
            'cumulative' => $type->isCumulative(),
            'filters' => $filters->describe(),
            'points' => $rows->map(fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'value' => (int) round((float) $row->total),
            ])->all(),
            'total' => (int) round((float) ($rows->sum('total') ?? 0)),
            'comparability' => $this->comparability->describe($selected, $available, [$type->value]),
        ];
    }

    /**
     * Follower totals over time, using the closing reading in each bucket.
     *
     * @return array<string, mixed>
     */
    public function followerGrowth(AnalyticsFilters $filters, string $granularity = 'day'): array
    {
        $bucket = $this->bucketExpression($granularity, 'analytics_metrics.period_start');

        $ranked = DB::query()
            ->fromSub(
                $this->scoped($filters)
                    ->where('metric_type', MetricType::Followers->value)
                    ->select('social_account_id', 'provider', 'period_start', 'value')
                    ->selectRaw($bucket.' as bucket')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY social_account_id, '.$bucket.' ORDER BY period_start DESC) as reading_rank')
                    ->toBase(),
                'ranked_followers',
            )
            ->where('reading_rank', 1)
            ->orderBy('bucket')
            ->get();

        $points = [];
        $previous = null;

        foreach ($ranked as $row) {
            $value = (int) round((float) $row->value);

            $points[] = [
                'bucket' => (string) $row->bucket,
                'followers' => $value,
                'change' => $previous === null ? null : $value - $previous,
            ];

            $previous = $value;
        }

        $available = $this->availability($filters, [MetricType::Followers->value]);
        $selected = $this->selectedPlatforms($filters, $available);

        return [
            'metric' => MetricType::Followers->value,
            'granularity' => $granularity,
            'convention' => 'Closing reading per account per bucket; a bucket total is the sum of those readings.',
            'points' => $points,
            'comparability' => $this->comparability->describe($selected, $available, [MetricType::Followers->value]),
        ];
    }

    /**
     * Per-platform comparison. Values are returned per platform and are only
     * summed where the comparability block says the definitions agree.
     *
     * @return array<string, mixed>
     */
    public function platformComparison(AnalyticsFilters $filters, MetricType|string|null $metric = null): array
    {
        $query = $this->scoped($filters);

        if ($metric !== null) {
            $query->where('metric_type', $metric instanceof MetricType ? $metric->value : $metric);
        }

        $matrix = $query
            ->selectRaw('provider')
            ->selectRaw('metric_type')
            ->selectRaw('SUM(value) as total')
            ->groupBy('provider')
            ->groupBy('metric_type')
            ->toBase()->get();

        $available = [];
        $platforms = [];

        foreach ($matrix as $row) {
            $provider = (string) $row->provider;
            $platforms[$provider] = true;
            $available[$provider][] = (string) $row->metric_type;
        }

        $selected = $this->toPlatforms(array_keys($platforms));
        $values = [];

        foreach ($matrix as $row) {
            $values[(string) $row->provider][(string) $row->metric_type] = (int) round((float) $row->total);
        }

        $metrics = $metric !== null
            ? [$metric instanceof MetricType ? $metric->value : $metric]
            : array_values(array_unique(array_merge(...array_values($available ?: [[]])) ?: []));

        $comparability = $this->comparability->describe($selected, $available, $metrics);

        $combined = [];

        foreach ($metrics as $name) {
            $entry = $comparability['metrics'][$name] ?? null;
            $sum = 0;

            foreach ($values as $platform) {
                $sum += (int) ($platform[$name] ?? 0);
            }

            $combined[$name] = [
                'value' => $entry['comparable'] ?? false ? $sum : null,
                'raw_sum' => $sum,
                'is_meaningful' => (bool) ($entry['comparable'] ?? false),
                'note' => ($entry['comparable'] ?? false)
                    ? null
                    : 'Shown per network only — the networks do not measure this the same way.',
            ];
        }

        return [
            'filters' => $filters->describe(),
            'platforms' => $selected,
            'values' => $values,
            'combined' => $combined,
            'comparability' => $comparability,
            'warnings' => $comparability['warnings'],
        ];
    }

    /**
     * Best-performing posts for the selection, ordered by the requested metric.
     *
     * @return array<string, mixed>
     */
    public function topPosts(AnalyticsFilters $filters, MetricType|string $metric = 'engagement', int $limit = 10): array
    {
        $type = $metric instanceof MetricType ? $metric : (MetricType::tryFrom($metric) ?? MetricType::Engagement);
        $limit = max(1, min($limit, 100));
        $contentType = $this->contentTypeExpression('post_variants.platform_specific');

        $rows = $this->scoped($filters)
            ->whereNotNull('post_variant_id')
            ->join('post_variants', 'post_variants.id', '=', 'analytics_metrics.post_variant_id')
            ->leftJoin('posts', 'posts.id', '=', 'post_variants.post_id')
            ->whereIn('post_variants.id', $this->liveVariantIds())
            ->where('analytics_metrics.metric_type', $type->value)
            ->selectRaw('post_variants.id as post_variant_id')
            ->selectRaw('post_variants.provider as post_provider')
            ->selectRaw('post_variants.provider_post_url as post_url')
            ->selectRaw('MAX(posts.title) as post_title')
            ->selectRaw($contentType.' as content_type')
            ->selectRaw('SUM(analytics_metrics.value) as total')
            ->groupBy('post_variants.id', 'post_variants.provider', 'post_variants.provider_post_url')
            ->groupByRaw($contentType)
            ->orderByDesc('total')
            ->limit($limit)
            ->toBase()->get();

        $available = $this->availability($filters, [$type->value]);
        $selected = $this->selectedPlatforms($filters, $available);

        return [
            'metric' => $type->value,
            'label' => $type->label(),
            'filters' => $filters->describe(),
            'posts' => $rows->map(fn ($row): array => [
                'post_variant_id' => (int) $row->post_variant_id,
                'provider' => (string) $row->post_provider,
                'url' => $row->post_url,
                'title' => $row->post_title,
                'content_type' => $row->content_type,
                'value' => (int) round((float) $row->total),
            ])->all(),
            'comparability' => $this->comparability->describe($selected, $available, [$type->value]),
        ];
    }

    /**
     * Performance grouped by the content type recorded on the variant.
     *
     * @return array<string, mixed>
     */
    public function contentTypePerformance(AnalyticsFilters $filters): array
    {
        $contentType = $this->contentTypeExpression('post_variants.platform_specific');

        $rows = $this->scoped($filters)
            ->whereNotNull('post_variant_id')
            ->join('post_variants', 'post_variants.id', '=', 'analytics_metrics.post_variant_id')
            ->whereIn('post_variants.id', $this->liveVariantIds())
            ->selectRaw($contentType.' as content_type')
            ->selectRaw('analytics_metrics.provider as provider')
            ->selectRaw('analytics_metrics.metric_type as metric_type')
            ->selectRaw('SUM(analytics_metrics.value) as total')
            ->selectRaw('COUNT(DISTINCT analytics_metrics.post_variant_id) as posts')
            ->groupByRaw($contentType)
            ->groupBy('analytics_metrics.provider')
            ->groupBy('analytics_metrics.metric_type')
            ->toBase()->get();

        $available = [];
        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) ($row->content_type ?? 'unclassified');
            $provider = (string) $row->provider;
            $type = (string) $row->metric_type;

            $available[$provider][] = $type;
            $grouped[$key][$provider][$type] = (int) round((float) $row->total);
            $grouped[$key]['_posts'] = max((int) ($grouped[$key]['_posts'] ?? 0), (int) $row->posts);
        }

        $selected = $this->selectedPlatforms($filters, $available);
        $metrics = array_values(array_unique(array_merge(...array_values($available ?: [[]])) ?: []));

        ksort($grouped);

        return [
            'filters' => $filters->describe(),
            'content_types' => $grouped,
            'comparability' => $this->comparability->describe($selected, $available, $metrics),
        ];
    }

    /**
     * How often content was published in the selection.
     *
     * @return array<string, mixed>
     */
    public function postingFrequency(AnalyticsFilters $filters, string $granularity = 'week'): array
    {
        $bucket = $this->bucketExpression($granularity, 'analytics_metrics.period_start');

        // A post that ran on three networks has three variants, so the totals
        // are counted without the provider grouping — adding the per-network
        // counts together would report it three times.
        $totals = $this->scoped($filters)
            ->whereNotNull('post_variant_id')
            ->selectRaw($bucket.' as bucket')
            ->selectRaw('COUNT(DISTINCT analytics_metrics.post_variant_id) as posts')
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->toBase()->get();

        $perPlatform = $this->scoped($filters)
            ->whereNotNull('post_variant_id')
            ->selectRaw($bucket.' as bucket')
            ->selectRaw('analytics_metrics.provider as provider')
            ->selectRaw('COUNT(DISTINCT analytics_metrics.post_variant_id) as posts')
            ->groupByRaw($bucket)
            ->groupBy('analytics_metrics.provider')
            ->orderByRaw($bucket)
            ->toBase()->get();

        $breakdown = [];

        foreach ($perPlatform as $row) {
            $breakdown[(string) $row->bucket][(string) $row->provider] = (int) $row->posts;
        }

        $series = [];

        foreach ($totals as $row) {
            $bucketKey = (string) $row->bucket;

            $series[] = [
                'bucket' => $bucketKey,
                'posts' => (int) $row->posts,
                'per_platform' => $breakdown[$bucketKey] ?? [],
            ];
        }

        $days = max(1, (int) ceil(
            (CarbonImmutable::parse($filters->to)->getTimestamp() - CarbonImmutable::parse($filters->from)->getTimestamp()) / 86_400
        ));

        $totalPosts = array_sum(array_column($series, 'posts'));

        return [
            'filters' => $filters->describe(),
            'granularity' => $granularity,
            'convention' => 'Distinct post variants per bucket. A post on several networks counts once, with the per-network split alongside.',
            'series' => $series,
            'total_posts' => $totalPosts,
            'posts_per_week' => round($totalPosts / max(1, $days / 7), 2),
            'days' => $days,
        ];
    }

    /**
     * Which metric types each selected network actually reported.
     *
     * @param  list<string>  $metrics
     * @return array<string, list<string>>
     */
    public function availability(AnalyticsFilters $filters, array $metrics = []): array
    {
        $rows = $this->scoped($filters)
            ->when($metrics !== [], fn (Builder $q) => $q->whereIn('metric_type', $metrics))
            ->selectRaw('provider')
            ->selectRaw('metric_type')
            ->groupBy('provider')
            ->groupBy('metric_type')
            ->toBase()->get();

        $available = [];

        foreach ($rows as $row) {
            $available[(string) $row->provider][] = (string) $row->metric_type;
        }

        return $available;
    }

    // ----------------------------------------------------------------- query

    /**
     * The one read path every method above goes through, so a filter can never
     * be silently dropped.
     */
    public function scoped(AnalyticsFilters $filters): Builder
    {
        return AnalyticsMetric::query()
            ->where('period_start', '>=', CarbonImmutable::parse($filters->from))
            ->where('period_start', '<=', CarbonImmutable::parse($filters->to))
            ->where(fn (Builder $q) => $q
                ->whereNull('metric_subtype')
                ->orWhere('metric_subtype', 'not like', SnapshotAggregator::UNATTRIBUTED_PREFIX.'%'))
            ->when($filters->platforms !== [], fn (Builder $q) => $q->whereIn('provider', array_map(
                static fn (SocialPlatform $platform): string => $platform->value,
                $filters->platforms,
            )))
            ->when($filters->accountIds !== [], fn (Builder $q) => $q->whereIn('social_account_id', $filters->accountIds))
            ->when($filters->requiresPostAttribution(), fn (Builder $q) => $q->whereIn(
                'post_variant_id',
                $this->variantSubquery($filters),
            ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function metricMatrix(AnalyticsFilters $filters): array
    {
        return $this->scoped($filters)
            ->selectRaw('provider')
            ->selectRaw('metric_type')
            ->selectRaw('SUM(value) as total')
            ->groupBy('provider')
            ->groupBy('metric_type')
            ->toBase()->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    private function variantSubquery(AnalyticsFilters $filters): Builder
    {
        return PostVariant::query()
            ->select('post_variants.id')
            ->when($filters->contentTypes !== [], fn (Builder $q) => $q->whereRaw(
                $this->contentTypeExpression('post_variants.platform_specific').' in ('.
                implode(', ', array_fill(0, count($filters->contentTypes), '?')).')',
                $filters->contentTypes,
            ))
            ->when($filters->campaignIds !== [], fn (Builder $q) => $q->whereHas(
                'post',
                fn (Builder $post) => $post->whereIn('campaign_id', $filters->campaignIds),
            ));
    }

    private function liveVariantIds(): Builder
    {
        return PostVariant::query()->select('post_variants.id');
    }

    /**
     * Follower gauge: the closing reading per account in the range, and the
     * change between the first and last of those readings.
     *
     * @return array{current: int, growth: int|null, previous: int|null, accounts: int}
     */
    private function followerTotals(AnalyticsFilters $filters): array
    {
        $latest = $this->gaugeReadings($filters, 'DESC');
        $earliest = $this->gaugeReadings($filters, 'ASC');

        if ($latest === []) {
            return ['current' => 0, 'growth' => null, 'previous' => null, 'accounts' => 0];
        }

        $current = 0;
        $previous = 0;

        foreach ($latest as $accountId => $reading) {
            $current += (int) $reading['value'];
            $previous += (int) ($earliest[$accountId]['value'] ?? $reading['value']);
        }

        return [
            'current' => $current,
            'growth' => $current - $previous,
            'previous' => $previous,
            'accounts' => count($latest),
        ];
    }

    /**
     * @return array<int, array{value: int, at: string}>
     */
    private function gaugeReadings(AnalyticsFilters $filters, string $direction): array
    {
        $rows = DB::query()
            ->fromSub(
                $this->scoped($filters)
                    ->where('metric_type', MetricType::Followers->value)
                    ->select('social_account_id', 'period_start', 'value')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY social_account_id ORDER BY period_start '.$direction.') as reading_rank')
                    ->toBase(),
                'ranked_gauges',
            )
            ->where('reading_rank', 1)
            ->get();

        $readings = [];

        foreach ($rows as $row) {
            $readings[(int) $row->social_account_id] = [
                'value' => (int) round((float) $row->value),
                'at' => (string) $row->period_start,
            ];
        }

        return $readings;
    }

    /**
     * Engagement is the sum of its components when the platform gave us the
     * parts, and the platform's own total when it did not. The basis is
     * reported so the dashboard can label the number.
     *
     * @param  array<string, int>  $totals
     * @return array{value: int|null, basis: string, components: list<string>}
     */
    private function engagements(array $totals): array
    {
        $components = MetricComparability::engagementComponents();
        $present = array_values(array_filter(
            $components,
            static fn (string $component): bool => array_key_exists($component, $totals),
        ));

        if ($present !== []) {
            $sum = array_sum(array_map(static fn (string $component): int => (int) $totals[$component], $present));

            return ['value' => $sum, 'basis' => 'components', 'components' => $present];
        }

        if (array_key_exists(MetricType::Engagement->value, $totals)) {
            return [
                'value' => (int) $totals[MetricType::Engagement->value],
                'basis' => 'provider_total',
                'components' => [],
            ];
        }

        return ['value' => null, 'basis' => 'none', 'components' => []];
    }

    /**
     * `engagements / reach` when reach was reported, otherwise
     * `engagements / followers`. Which one was used is part of the answer.
     *
     * @param  array<string, int>  $totals
     * @return array<string, mixed>
     */
    private function engagementRate(?int $engagements, array $totals, int $followers): array
    {
        $reach = (int) ($totals[MetricType::Reach->value] ?? 0);
        $hasReach = array_key_exists(MetricType::Reach->value, $totals) && $reach > 0;

        if ($engagements === null) {
            return [
                'value' => null,
                'denominator' => null,
                'denominator_value' => null,
                'label' => 'No engagement was reported for this selection.',
                'numerator' => null,
            ];
        }

        if ($hasReach) {
            return [
                'value' => round($engagements / $reach, 4),
                'denominator' => MetricType::Reach->value,
                'denominator_value' => $reach,
                'label' => 'Engagements divided by reach',
                'numerator' => $engagements,
            ];
        }

        if ($followers > 0) {
            return [
                'value' => round($engagements / $followers, 4),
                'denominator' => MetricType::Followers->value,
                'denominator_value' => $followers,
                'label' => 'Engagements divided by followers (no reach was reported)',
                'numerator' => $engagements,
            ];
        }

        return [
            'value' => null,
            'denominator' => null,
            'denominator_value' => null,
            'label' => 'No reach or follower total was reported, so an engagement rate cannot be computed.',
            'numerator' => $engagements,
        ];
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, list<string>>  $available
     * @param  list<SocialPlatform>  $selected
     * @return array<string, list<string>>
     */
    private function reportedBy(array $totals, array $available, array $selected): array
    {
        $reported = [];

        foreach ($totals as $metric => $value) {
            if ($metric === MetricType::FollowerGains->value || $metric === MetricType::FollowerLosses->value) {
                continue;
            }

            $reported[$metric] = array_values(array_filter(
                array_map(static fn (SocialPlatform $platform): string => $platform->value, $selected),
                static fn (string $platform): bool => in_array($metric, $available[$platform] ?? [], true),
            ));
        }

        return $reported;
    }

    /**
     * @param  array<string, list<string>>  $available
     * @return list<SocialPlatform>
     */
    private function selectedPlatforms(AnalyticsFilters $filters, array $available): array
    {
        if ($filters->platforms !== []) {
            return $filters->platforms;
        }

        return $this->toPlatforms(array_keys($available));
    }

    /**
     * @param  list<string>  $keys
     * @return list<SocialPlatform>
     */
    private function toPlatforms(array $keys): array
    {
        $platforms = [];

        foreach ($keys as $key) {
            if ($key === '__all') {
                continue;
            }

            $platform = SocialPlatform::tryFrom((string) $key);

            if ($platform !== null && ! in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }

    // ------------------------------------------------------------ expressions

    private function bucketExpression(string $granularity, string $column): string
    {
        $driver = DB::connection()->getDriverName();
        $col = $this->column($column, $driver);

        return match ($granularity) {
            'hour' => match ($driver) {
                'mysql', 'mariadb' => sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')", $col),
                'pgsql' => sprintf("to_char(%s, 'YYYY-MM-DD HH24:00:00')", $col),
                default => sprintf("strftime('%%Y-%%m-%%d %%H:00:00', %s)", $col),
            },
            'week' => match ($driver) {
                'mysql', 'mariadb' => sprintf("DATE_FORMAT(DATE_SUB(DATE(%s), INTERVAL WEEKDAY(%s) DAY), '%%Y-%%m-%%d')", $col, $col),
                'pgsql' => sprintf("to_char(date_trunc('week', %s), 'YYYY-MM-DD')", $col),
                default => sprintf("date(%s, '-' || ((CAST(strftime('%%w', %s) AS INTEGER) + 6) %% 7) || ' days')", $col, $col),
            },
            'month' => match ($driver) {
                'mysql', 'mariadb' => sprintf("DATE_FORMAT(%s, '%%Y-%%m-01')", $col),
                'pgsql' => sprintf("to_char(date_trunc('month', %s), 'YYYY-MM-DD')", $col),
                default => sprintf("date(%s, 'start of month')", $col),
            },
            default => match ($driver) {
                'mysql', 'mariadb' => sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d')", $col),
                'pgsql' => sprintf("to_char(%s, 'YYYY-MM-DD')", $col),
                default => sprintf('date(%s)', $col),
            },
        };
    }

    private function contentTypeExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();
        $col = $this->column($column, $driver);
        $path = "'$.content_type'";

        return match ($driver) {
            'mysql', 'mariadb' => sprintf('JSON_UNQUOTE(JSON_EXTRACT(%s, %s))', $col, $path),
            'pgsql' => sprintf("%s->>'content_type'", $col),
            default => sprintf('json_extract(%s, %s)', $col, $path),
        };
    }

    /**
     * SQLite's expression parser rejects a qualified column name inside a
     * function argument, so it is quoted there and left bare elsewhere.
     */
    private function column(string $qualified, string $driver): string
    {
        if ($driver !== 'sqlite') {
            return $qualified;
        }

        return implode('.', array_map(
            static fn (string $part): string => sprintf('"%s"', $part),
            explode('.', $qualified),
        ));
    }
}
