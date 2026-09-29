<?php

declare(strict_types=1);

use App\Enums\MetricType;
use App\Enums\PostVariantStatus;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsIngestService;
use App\Services\Analytics\MetricNormalizer;
use App\Services\Analytics\SnapshotAggregator;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\FollowerStats;
use App\Services\Social\Data\MetricSample;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());

afterEach(fn () => TenantContext::forget());

/**
 * Create a tenant, pin it as the resolved context, and return it.
 */
function analyticsTenant(int $retentionDays = 30): Tenant
{
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);

    DB::table('tenants')->where('id', $tenant->id)->update(['analytics_retention_days' => $retentionDays]);

    $tenant->refresh();

    TenantContext::set($tenant);

    return $tenant;
}

function analyticsAccount(Tenant $tenant, SocialPlatform $platform, ?string $providerPostId = null): SocialAccount
{
    $account = SocialAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => $platform,
        'status' => SocialAccountStatus::Connected->value,
    ]);

    if ($providerPostId !== null) {
        PostVariant::factory()->create([
            'post_id' => Post::factory()->create(['tenant_id' => $tenant->id])->id,
            'social_account_id' => $account->id,
            'provider' => $platform,
            'status' => PostVariantStatus::Published->value,
            'provider_post_id' => $providerPostId,
        ]);
    }

    return $account;
}

/**
 * A provider batch that deliberately omits `reach` — the platform never sent it.
 *
 * @param  list<MetricSample>  $postSamples
 */
function providerBatch(SocialPlatform $platform, array $postSamples = [], ?string $raw = null): AnalyticsBatch
{
    $from = Carbon::parse('2026-09-20 00:00:00');
    $to = Carbon::parse('2026-09-21 00:00:00');

    return new AnalyticsBatch(
        provider: $platform->value,
        periodStart: $from,
        periodEnd: $to,
        accountMetrics: [
            new MetricSample(metric: 'views', value: 100, periodStart: $from->toIso8601String(), periodEnd: $to->toIso8601String()),
            new MetricSample(metric: 'likes', value: 12, periodStart: $from->toIso8601String(), periodEnd: $to->toIso8601String()),
        ],
        postMetrics: $postSamples === [] ? [] : ['post-1' => $postSamples],
        granularity: 'day',
        raw: $raw === null ? [] : ['views' => 100, 'access_token' => 'super-secret-token'],
    );
}

it('re-ingesting the same period updates rather than duplicating', function () {
    $tenant = analyticsTenant();
    $account = analyticsAccount($tenant, SocialPlatform::Facebook);

    $ingest = app(AnalyticsIngestService::class);
    $batch = providerBatch(SocialPlatform::Facebook);

    $first = $ingest->ingestBatch($account, $batch);
    expect($first->inserted)->toBe(2);
    expect(AnalyticsMetric::query()->count())->toBe(2);

    $second = $ingest->ingestBatch($account, $batch);
    expect($second->inserted)->toBe(0)
        ->and($second->updated)->toBe(0)
        ->and(AnalyticsMetric::query()->count())->toBe(2);

    $corrected = $batch;
    $corrected = new AnalyticsBatch(
        provider: $batch->provider,
        periodStart: $batch->periodStart,
        periodEnd: $batch->periodEnd,
        accountMetrics: [
            new MetricSample(metric: 'views', value: 250, periodStart: $batch->periodStart->toIso8601String()),
            new MetricSample(metric: 'likes', value: 12, periodStart: $batch->periodStart->toIso8601String()),
        ],
    );

    $third = $ingest->ingestBatch($account, $corrected);

    expect($third->inserted)->toBe(0)
        ->and($third->updated)->toBe(1)
        ->and(AnalyticsMetric::query()->count())->toBe(2)
        ->and(AnalyticsMetric::query()->where('metric_type', MetricType::Views->value)->first()->value)->toBe(250);
});

it('does not store a metric the provider never reported as zero', function () {
    $tenant = analyticsTenant();
    $account = analyticsAccount($tenant, SocialPlatform::Instagram);

    $ingest = app(AnalyticsIngestService::class);
    $ingest->ingestBatch($account, providerBatch(SocialPlatform::Instagram));

    $stored = AnalyticsMetric::query()->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->map(fn (AnalyticsMetric $m): string => $m->metric_type->value)->all())
        ->toEqualCanonicalizing(['views', 'likes'])
        ->and(AnalyticsMetric::query()->where('metric_type', MetricType::Reach->value)->exists())->toBeFalse()
        ->and(AnalyticsMetric::query()->where('value', 0)->exists())->toBeFalse();
});

it('attaches post metrics to the matching variant and flags unknown posts', function () {
    $tenant = analyticsTenant();
    $account = analyticsAccount($tenant, SocialPlatform::Facebook, 'post-1');

    $ingest = app(AnalyticsIngestService::class);

    $batch = providerBatch(SocialPlatform::Facebook, [
        new MetricSample(metric: 'views', value: 40, periodStart: '2026-09-20T00:00:00+00:00'),
    ]);

    $result = $ingest->ingestBatch($account, $batch);

    $variantMetric = AnalyticsMetric::query()
        ->where('metric_type', MetricType::Views->value)
        ->whereNotNull('post_variant_id')
        ->first();

    expect($variantMetric)->not->toBeNull()
        ->and($variantMetric->value)->toBe(40)
        ->and($result->unmatchedPostIds)->toBe([]);

    $orphan = $ingest->ingestBatch($account, new AnalyticsBatch(
        provider: 'facebook',
        periodStart: Carbon::parse('2026-09-20'),
        periodEnd: Carbon::parse('2026-09-21'),
        postMetrics: ['unmanaged-post' => [new MetricSample(metric: 'comments', value: 3, periodStart: '2026-09-20T00:00:00+00:00')]],
    ));

    expect($orphan->unmatchedPostIds)->toBe(['unmanaged-post']);

    $flagged = AnalyticsMetric::query()
        ->where('metric_type', MetricType::Comments->value)
        ->whereNull('post_variant_id')
        ->first();

    expect($flagged)->not->toBeNull()
        ->and($flagged->metric_subtype)->toBe('unattributed:post:unmanaged-post');
});

it('refuses to write periods older than the tenant retention window', function () {
    $tenant = analyticsTenant(retentionDays: 30);
    $account = analyticsAccount($tenant, SocialPlatform::X);

    $old = new AnalyticsBatch(
        provider: 'x',
        periodStart: Carbon::parse('2020-01-01'),
        periodEnd: Carbon::parse('2020-01-02'),
        accountMetrics: [new MetricSample(metric: 'likes', value: 5, periodStart: '2020-01-01T00:00:00+00:00')],
    );

    $ingest = app(AnalyticsIngestService::class);
    $result = $ingest->ingestBatch($account, $old);

    expect($result->written())->toBe(0)
        ->and($result->skippedOutOfRetention)->toBe(1)
        ->and(AnalyticsMetric::query()->count())->toBe(0);
});

it('never writes credentials or oversized payloads into raw_response', function () {
    $tenant = analyticsTenant();
    $account = analyticsAccount($tenant, SocialPlatform::Facebook);

    $ingest = app(AnalyticsIngestService::class);
    $ingest->ingestBatch($account, providerBatch(SocialPlatform::Facebook, [], json_encode([
        'views' => 100,
        'access_token' => 'super-secret-token',
        'nested' => ['refresh_token' => 'another-secret', 'views' => 5],
    ])));

    $stored = AnalyticsMetric::query()->first()->raw_response;

    expect(json_encode($stored))->not->toContain('super-secret-token')
        ->and(json_encode($stored))->not->toContain('another-secret')
        ->and($stored['views'])->toBe(100);
});

it('records follower totals as a gauge at the provider as-of time', function () {
    $tenant = analyticsTenant();
    $account = analyticsAccount($tenant, SocialPlatform::TikTok);

    $ingest = app(AnalyticsIngestService::class);
    $ingest->ingestAccountAnalytics($account, new FollowerStats(
        provider: 'tiktok',
        providerAccountId: 'abc',
        total: 1234,
        change: 56,
        asOf: Carbon::parse('2026-09-20 12:00:00'),
    ));

    expect(AnalyticsMetric::query()->where('metric_type', MetricType::Followers->value)->first()->value)->toBe(1234)
        ->and(AnalyticsMetric::query()->where('metric_type', MetricType::FollowerGains->value)->first()->value)->toBe(56);
});

it('preserves the provider metric subtype when one is reported', function () {
    $tenant = analyticsTenant();
    $account = analyticsAccount($tenant, SocialPlatform::Facebook);

    $normalizer = app(MetricNormalizer::class);

    $normalized = $normalizer->normalizeSample(
        new MetricSample(metric: 'impressions', value: 900, dimensions: ['subtype' => 'paid']),
    );

    app(AnalyticsIngestService::class)->ingestRawPayload(
        $account,
        ['impressions' => [['value' => 900, 'period_start' => '2026-09-20', 'subtype' => 'paid']]],
    );

    $metric = AnalyticsMetric::query()->where('metric_type', MetricType::Impressions->value)->first();

    expect($normalized->metricSubtype)->toBe('paid')
        ->and($metric->metric_subtype)->toBe('paid')
        ->and($metric->value)->toBe(900);
});

it('rolls raw metrics up into snapshots idempotently', function () {
    $tenant = analyticsTenant();
    $account = analyticsAccount($tenant, SocialPlatform::Facebook, 'post-1');
    $variant = PostVariant::query()->where('provider_post_id', 'post-1')->firstOrFail();

    $ingest = app(AnalyticsIngestService::class);
    $aggregator = app(SnapshotAggregator::class);

    $ingest->ingestBatch($account, new AnalyticsBatch(
        provider: 'facebook',
        periodStart: Carbon::parse('2026-09-20 00:00:00'),
        periodEnd: Carbon::parse('2026-09-21 00:00:00'),
        accountMetrics: [
            new MetricSample(metric: 'views', value: 100, periodStart: '2026-09-20T00:00:00+00:00'),
            new MetricSample(metric: 'likes', value: 10, periodStart: '2026-09-20T00:00:00+00:00'),
        ],
        postMetrics: ['post-1' => [new MetricSample(metric: 'views', value: 60, periodStart: '2026-09-20T00:00:00+00:00')]],
    ));
    $ingest->ingestAccountAnalytics($account, new FollowerStats(
        provider: 'facebook',
        providerAccountId: 'x',
        total: 900,
        asOf: Carbon::parse('2026-09-20 06:00:00'),
    ));

    $first = $aggregator->aggregateForAccount($account, ['daily']);
    $afterFirst = AnalyticsSnapshot::query()->count();
    $payload = AnalyticsSnapshot::query()
        ->whereNull('post_variant_id')
        ->where('social_account_id', $account->id)
        ->where('period', 'daily')
        ->first()?->metrics;

    $second = $aggregator->aggregateForAccount($account, ['daily']);

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and(AnalyticsSnapshot::query()->count())->toBe($afterFirst)
        ->and($payload['views'])->toBe(100)
        ->and($payload['followers'])->toBe(900)
        ->and($payload['variants'][(int) $variant->id]['views'])->toBe(60)
        ->and($payload)->not->toHaveKey('reach');
});
