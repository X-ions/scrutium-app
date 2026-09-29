<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\MetricType;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rolls raw `analytics_metrics` rows up into `analytics_snapshots` at hourly,
 * daily, weekly and monthly granularity, for account level, variant level and
 * tenant level.
 *
 * ## Cumulative vs. interval — the convention
 *
 * A snapshot's `metrics` map is always an **interval** figure: the activity that
 * occurred *within* the bucket. Two families of metric are not reported that
 * way by the providers, and each is folded in explicitly rather than summed:
 *
 * - **Cumulative gauges** — `MetricType::isCumulative()` (`followers`,
 *   `follower_gains`, `follower_losses`). These are point-in-time readings or
 *   provider-reported deltas, so adding a month of daily follower counts would
 *   multiply the audience size by thirty. The aggregator takes the **last
 *   reading in the bucket**, which is that bucket's closing value.
 * - **Provider-reported totals** — `engagement` is a total the platform computed
 *   for its own window. It is summed like any other interval value, but a
 *   bucket never contains both a total and its components for the same period,
 *   because the normaliser maps one canonical metric per provider key and the
 *   components (`likes`, `comments`, `shares`, `saves`, `clicks`) are distinct
 *   `MetricType` values a reader may add up instead.
 *
 * A metric that appears in no source row contributes **no key at all** to the
 * `metrics` map. Absence stays absence: a snapshot never contains
 * `"reach": 0` for a platform that does not report reach.
 *
 * ## Hourly
 *
 * `analytics_snapshots.date` is a DATE column, so an hourly bucket has no row
 * key of its own — twelve hourly snapshots for one day would collide on the
 * unique index. Hourly aggregation therefore produces one row per day whose
 * `metrics.hours` map holds each hour's own readings, keyed by
 * `YYYY-MM-DD HH:00:00`. The granularity is exact; only the storage shape is
 * dictated by the schema.
 *
 * ## Levels
 *
 * One grouping pass feeds both levels. A variant bucket holds that variant's
 * own readings; the account bucket prefers the account's own readings for any
 * metric it reported and falls back to summing the per-variant readings for
 * metrics it did not — the two are the same activity seen twice, not two
 * activities — and always carries the per-variant breakdown under
 * `metrics.variants.<post_variant_id>` so a total can be taken apart.
 *
 * ## Idempotency
 *
 * Rows are matched on the full unique identity
 * `(tenant_id, social_account_id, post_variant_id, provider, date, period)` and
 * updated in place. Nothing run-varying (no "aggregated at" stamp) is written
 * into the payload, so re-running produces a byte-identical row rather than a
 * duplicate or a churned update.
 */
class SnapshotAggregator
{
    public const PERIOD_HOURLY = 'hourly';

    public const PERIOD_DAILY = 'daily';

    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_MONTHLY = 'monthly';

    /**
     * @var list<string>
     */
    public const PERIODS = [
        self::PERIOD_HOURLY,
        self::PERIOD_DAILY,
        self::PERIOD_WEEKLY,
        self::PERIOD_MONTHLY,
    ];

    /**
     * Metric subtypes holding readings for posts the CMS never published. They
     * are stored rather than discarded — a reading is never thrown away — but
     * they are not activity on the account either, and folding them into a
     * snapshot would double-count against the account's own totals.
     */
    public const UNATTRIBUTED_PREFIX = 'unattributed:';

    /**
     * @param  list<string>  $periods
     */
    public function aggregateForAccount(
        SocialAccount $account,
        array $periods = self::PERIODS,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
    ): int {
        return $this->withinTenant($account->tenant, function () use ($account, $periods, $from, $to): int {
            $written = 0;

            foreach ($periods as $period) {
                $written += $this->writeAccountPeriod($account, $period, $from, $to);
            }

            return $written;
        });
    }

    /**
     * Tenant-level rows, kept per platform so a snapshot never sums two
     * platforms' differently-defined metrics into one number.
     *
     * @param  list<string>  $periods
     */
    public function aggregateForTenant(
        Tenant|int $tenant,
        array $periods = self::PERIODS,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
    ): int {
        $model = $tenant instanceof Tenant ? $tenant : Tenant::findOrFail($tenant);

        return $this->withinTenant($model, function () use ($model, $periods, $from, $to): int {
            $written = 0;

            foreach ($periods as $period) {
                $written += $this->writeTenantPeriod($model, $period, $from, $to);
            }

            return $written;
        });
    }

    /**
     * Account-level and tenant-level aggregation in one call. This is what the
     * post-ingestion job runs.
     */
    public function aggregateAfterIngestion(SocialAccount $account, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): int
    {
        $written = $this->aggregateForAccount($account, self::PERIODS, $from, $to);

        $tenant = $account->tenant;

        if ($tenant !== null) {
            $written += $this->aggregateForTenant($tenant, self::PERIODS, $from, $to);
        }

        return $written;
    }

    // ------------------------------------------------------------------ write

    private function writeAccountPeriod(SocialAccount $account, string $period, ?DateTimeInterface $from, ?DateTimeInterface $to): int
    {
        $buckets = $this->variantBuckets($account, $period, $from, $to);

        if ($buckets === []) {
            return 0;
        }

        $rows = [];

        foreach ($buckets as $bucket) {
            if ($bucket['variant_id'] === null) {
                continue;
            }

            $date = substr((string) $bucket['bucket_date'], 0, 10);

            $rows[$this->identity(
                (int) $account->tenant_id,
                (int) $account->getKey(),
                (int) $bucket['variant_id'],
                $account->provider->value,
                $date,
                $period,
            )] = [
                'tenant_id' => (int) $account->tenant_id,
                'social_account_id' => (int) $account->getKey(),
                'post_variant_id' => (int) $bucket['variant_id'],
                'provider' => $account->provider->value,
                'date' => $date,
                'period' => $period,
                'metrics' => $this->payload($bucket),
            ];
        }

        foreach ($this->collapse($buckets) as $date => $bucket) {
            $rows[$this->identity(
                (int) $account->tenant_id,
                (int) $account->getKey(),
                null,
                $account->provider->value,
                (string) $date,
                $period,
            )] = [
                'tenant_id' => (int) $account->tenant_id,
                'social_account_id' => (int) $account->getKey(),
                'post_variant_id' => null,
                'provider' => $account->provider->value,
                'date' => (string) $date,
                'period' => $period,
                'metrics' => $this->payload($bucket),
            ];
        }

        return $this->persistSnapshots($rows);
    }

    private function writeTenantPeriod(Tenant $tenant, string $period, ?DateTimeInterface $from, ?DateTimeInterface $to): int
    {
        $query = $this->scopedQuery()
            ->when($from !== null, fn (Builder $q) => $q->where('period_start', '>=', CarbonImmutable::parse($from)))
            ->when($to !== null, fn (Builder $q) => $q->where('period_start', '<=', CarbonImmutable::parse($to)));

        $buckets = $this->group($query, $period, false);

        if ($buckets === []) {
            return 0;
        }

        $rows = [];

        foreach ($buckets as $bucket) {
            $date = substr((string) $bucket['bucket_date'], 0, 10);
            $provider = (string) $bucket['provider'];

            $rows[$this->identity((int) $tenant->id, null, null, $provider, $date, $period)] = [
                'tenant_id' => (int) $tenant->id,
                'social_account_id' => null,
                'post_variant_id' => null,
                'provider' => $provider,
                'date' => $date,
                'period' => $period,
                'metrics' => $this->payload($bucket),
            ];
        }

        return $this->persistSnapshots($rows);
    }

    // ------------------------------------------------------------------ query

    /**
     * @return list<array<string, mixed>>
     */
    private function variantBuckets(SocialAccount $account, string $period, ?DateTimeInterface $from, ?DateTimeInterface $to): array
    {
        $query = $this->scopedQuery()
            ->where('social_account_id', $account->getKey())
            ->when($from !== null, fn (Builder $q) => $q->where('period_start', '>=', CarbonImmutable::parse($from)))
            ->when($to !== null, fn (Builder $q) => $q->where('period_start', '<=', CarbonImmutable::parse($to)));

        return $this->group($query, $period, true);
    }

    private function scopedQuery(): Builder
    {
        return AnalyticsMetric::query()
            ->where(fn (Builder $q) => $q
                ->whereNull('metric_subtype')
                ->orWhere('metric_subtype', 'not like', self::UNATTRIBUTED_PREFIX.'%'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function group(Builder $query, string $period, bool $byVariant): array
    {
        $dateExpr = $this->dateExpression($period, 'analytics_metrics.period_start');
        $bucketExpr = $period === self::PERIOD_HOURLY
            ? $this->hourExpression('analytics_metrics.period_start')
            : null;

        $columns = [$dateExpr.' as bucket_date', 'metric_type'];

        if ($bucketExpr !== null) {
            $columns[] = $bucketExpr.' as bucket_key';
        }

        if ($byVariant) {
            $columns[] = 'post_variant_id';
        } else {
            $columns[] = 'provider';
        }

        $rows = [
            ...$this->aggregate($query, $period, $dateExpr, $bucketExpr, $columns, $this->intervalTypes(), 'SUM'),
            ...$this->aggregate($query, $period, $dateExpr, $bucketExpr, $columns, $this->cumulativeTypes(), 'MAX'),
        ];

        return $this->fold($rows, $byVariant);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function aggregate(Builder $query, string $period, string $dateExpr, ?string $bucketExpr, array $columns, array $types, string $function): array
    {
        if ($types === []) {
            return [];
        }

        $builder = clone $query;
        $builder->selectRaw(implode(', ', [
            ...$columns,
            $function.'(value) as aggregate_value',
            'COUNT(*) as source_rows',
        ]));

        $builder->whereIn('metric_type', $types)
            ->groupByRaw($dateExpr);

        if ($bucketExpr !== null) {
            $builder->groupByRaw($bucketExpr);
        }

        $builder->groupBy('metric_type');

        if (in_array('post_variant_id', $columns, true)) {
            $builder->groupBy('post_variant_id');
        } else {
            $builder->groupBy('provider');
        }

        return $builder->get()->map(fn ($row): array => $row->getAttributes())->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function fold(array $rows, bool $byVariant): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $bucketDate = (string) ($row['bucket_date'] ?? '');

            if ($bucketDate === '') {
                continue;
            }

            $hour = isset($row['bucket_key']) ? (string) $row['bucket_key'] : null;
            $variant = $byVariant ? $row['post_variant_id'] : null;

            $key = implode('|', [$bucketDate, $hour ?? '', $byVariant ? (string) $variant : (string) ($row['provider'] ?? '')]);

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'bucket_date' => $bucketDate,
                    'bucket_key' => $hour,
                    'variant_id' => $variant === null ? null : (int) $variant,
                    'provider' => isset($row['provider']) ? (string) $row['provider'] : null,
                    'values' => [],
                    'hours' => [],
                    'source_rows' => 0,
                ];
            }

            $type = (string) ($row['metric_type'] ?? '');
            $value = (int) round((float) ($row['aggregate_value'] ?? 0));

            if ($hour !== null) {
                $buckets[$key]['hours'][$hour][$type] = ($buckets[$key]['hours'][$hour][$type] ?? 0) + $value;
            }

            $buckets[$key]['values'][$type] = ($buckets[$key]['values'][$type] ?? 0) + $value;
            $buckets[$key]['source_rows'] += (int) ($row['source_rows'] ?? 0);
        }

        return array_values($buckets);
    }

    /**
     * Sum variant buckets into one account-level bucket per date, keeping the
     * per-variant breakdown alongside the total.
     *
     * An account-level reading and its own per-post readings are two views of
     * the same activity: Meta's account insights for a day already include the
     * views of the posts in it. Adding them would report every impression
     * twice. So the account's own reading wins for any metric it reported, and
     * the per-post readings are only summed in for metrics the account never
     * reported at all — which is exactly the case for providers that only
     * report post-level insights.
     *
     * @param  list<array<string, mixed>>  $buckets
     * @return array<string, array<string, mixed>>
     */
    private function collapse(array $buckets): array
    {
        $byDate = [];

        foreach ($buckets as $bucket) {
            $date = substr((string) $bucket['bucket_date'], 0, 10);

            if (! isset($byDate[$date])) {
                $byDate[$date] = [
                    'bucket_date' => (string) $bucket['bucket_date'],
                    'bucket_key' => $bucket['bucket_key'] ?? null,
                    'variant_id' => null,
                    'provider' => $bucket['provider'] ?? null,
                    'values' => [],
                    'hours' => [],
                    'variants' => [],
                    'source_rows' => 0,
                    '_own' => [],
                    '_from_variants' => [],
                ];
            }

            $target = &$byDate[$date];

            $target['source_rows'] += (int) $bucket['source_rows'];

            $isOwn = $bucket['variant_id'] === null;

            foreach ($bucket['values'] as $type => $value) {
                $sink = $isOwn ? '_own' : '_from_variants';

                $target[$sink][$type] = ($target[$sink][$type] ?? 0) + (int) $value;
            }

            foreach ($bucket['hours'] as $hour => $values) {
                foreach ($values as $type => $value) {
                    $sink = $isOwn ? '_own' : '_from_variants';

                    $target['hours'][$hour][$type] = ($target['hours'][$hour][$type] ?? 0) + (int) $value;
                }
            }

            if ($bucket['variant_id'] !== null) {
                $target['variants'][(int) $bucket['variant_id']] = $bucket['values'];
            }

            unset($target);
        }

        foreach ($byDate as $date => $target) {
            $byDate[$date]['values'] = array_merge($target['_from_variants'], $target['_own']);
            unset($byDate[$date]['_own'], $byDate[$date]['_from_variants']);
        }

        return $byDate;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function payload(array $bucket): array
    {
        $payload = $bucket['values'];

        if (! empty($bucket['hours'])) {
            $payload['hours'] = $bucket['hours'];
        }

        if (! empty($bucket['variants'])) {
            $payload['variants'] = $bucket['variants'];
        }

        $payload['_meta'] = [
            'bucket_start' => substr((string) $bucket['bucket_date'], 0, 10),
            'hourly' => ($bucket['bucket_key'] ?? null) !== null,
            'source_rows' => (int) $bucket['source_rows'],
            'convention' => 'interval sums; cumulative gauges take the closing reading',
        ];

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function intervalTypes(): array
    {
        return array_values(array_map(
            static fn (MetricType $type): string => $type->value,
            array_filter(MetricType::cases(), static fn (MetricType $type): bool => ! $type->isCumulative()),
        ));
    }

    /**
     * @return list<string>
     */
    private function cumulativeTypes(): array
    {
        return array_values(array_map(
            static fn (MetricType $type): string => $type->value,
            array_filter(MetricType::cases(), static fn (MetricType $type): bool => $type->isCumulative()),
        ));
    }

    // ---------------------------------------------------------------- persist

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function persistSnapshots(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $existing = $this->existingSnapshots(array_values($rows));

        $written = 0;

        DB::transaction(function () use ($rows, $existing, &$written): void {
            foreach ($rows as $key => $row) {
                $match = $existing[$key] ?? null;

                if ($match instanceof AnalyticsSnapshot) {
                    if (json_encode($match->metrics) === json_encode($row['metrics'])) {
                        continue;
                    }

                    $match->forceFill(['metrics' => $row['metrics']])->save();

                    $written++;

                    continue;
                }

                $snapshot = new AnalyticsSnapshot;
                $snapshot->forceFill($row);
                $snapshot->save();

                $written++;
            }
        });

        return $written;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, AnalyticsSnapshot>
     */
    private function existingSnapshots(array $rows): array
    {
        $accounts = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?int => $row['social_account_id'] === null ? null : (int) $row['social_account_id'],
            $rows,
        ))));

        // `date` is written through the model's date cast, so it lands in the
        // column as a full `Y-m-d H:i:s` midnight value. The lookup has to
        // speak the same format or a re-run would miss every row it wrote.
        $dates = array_values(array_unique(array_map(
            static fn (array $row): string => CarbonImmutable::parse((string) $row['date'])->startOfDay()->toDateTimeString(),
            $rows,
        )));

        $periods = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['period'],
            $rows,
        )));

        $candidates = AnalyticsSnapshot::query()
            ->whereIn('date', $dates)
            ->whereIn('period', $periods)
            ->when($accounts !== [], fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner->whereIn('social_account_id', $accounts)->orWhereNull('social_account_id'),
            ))
            ->get()
            ->all();

        $found = [];

        foreach ($candidates as $candidate) {
            $found[$this->snapshotKey($candidate)] = $candidate;
        }

        return $found;
    }

    private function identity(
        int $tenantId,
        ?int $socialAccountId,
        ?int $postVariantId,
        ?string $provider,
        string $date,
        string $period,
    ): string {
        return implode('|', [
            $tenantId,
            $socialAccountId ?? "\0",
            $postVariantId ?? "\0",
            $provider ?? "\0",
            $date,
            $period,
        ]);
    }

    private function snapshotKey(AnalyticsSnapshot $snapshot): string
    {
        return $this->identity(
            (int) $snapshot->tenant_id,
            $snapshot->social_account_id === null ? null : (int) $snapshot->social_account_id,
            $snapshot->post_variant_id === null ? null : (int) $snapshot->post_variant_id,
            $snapshot->provider?->value,
            $snapshot->date?->format('Y-m-d') ?? '',
            (string) $snapshot->period,
        );
    }

    // ------------------------------------------------------------ expressions

    private function dateExpression(string $period, string $column): string
    {
        $driver = DB::connection()->getDriverName();
        $col = $this->column($column, $driver);

        return match ($driver) {
            'mysql', 'mariadb' => match ($period) {
                self::PERIOD_MONTHLY => sprintf("DATE_FORMAT(%s, '%%Y-%%m-01')", $col),
                self::PERIOD_WEEKLY => sprintf("DATE_FORMAT(DATE_SUB(DATE(%s), INTERVAL WEEKDAY(%s) DAY), '%%Y-%%m-%%d')", $col, $col),
                default => sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d')", $col),
            },
            'pgsql' => match ($period) {
                self::PERIOD_MONTHLY => sprintf("to_char(date_trunc('month', %s), 'YYYY-MM-DD')", $col),
                self::PERIOD_WEEKLY => sprintf("to_char(date_trunc('week', %s), 'YYYY-MM-DD')", $col),
                default => sprintf("to_char(%s, 'YYYY-MM-DD')", $col),
            },
            default => match ($period) {
                self::PERIOD_MONTHLY => sprintf("date(%s, 'start of month')", $col),
                self::PERIOD_WEEKLY => sprintf("date(%s, '-' || ((CAST(strftime('%%w', %s) AS INTEGER) + 6) %% 7) || ' days')", $col, $col),
                default => sprintf('date(%s)', $col),
            },
        };
    }

    private function hourExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();
        $col = $this->column($column, $driver);

        return match ($driver) {
            'mysql', 'mariadb' => sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')", $col),
            'pgsql' => sprintf("to_char(%s, 'YYYY-MM-DD HH24:00:00')", $col),
            default => sprintf("strftime('%%Y-%%m-%%d %%H:00:00', %s)", $col),
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

        $parts = explode('.', $qualified);

        return implode('.', array_map(
            static fn (string $part): string => sprintf('"%s"', $part),
            $parts,
        ));
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function withinTenant(?Tenant $tenant, callable $callback): mixed
    {
        if ($tenant === null) {
            return $callback();
        }

        $previous = TenantContext::tenant();

        TenantContext::set($tenant);

        try {
            return $callback();
        } finally {
            TenantContext::set($previous);
        }
    }
}
