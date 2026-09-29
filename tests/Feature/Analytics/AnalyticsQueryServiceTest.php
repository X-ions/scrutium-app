<?php

declare(strict_types=1);

use App\Enums\MetricType;
use App\Enums\PostVariantStatus;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\AnalyticsMetric;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsQueryService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());

afterEach(fn () => TenantContext::forget());

function queryTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);

    TenantContext::set($tenant);

    return $tenant;
}

function queryAccount(Tenant $tenant, SocialPlatform $platform): SocialAccount
{
    return SocialAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => $platform,
        'status' => SocialAccountStatus::Connected->value,
    ]);
}

/**
 * A published variant with a content type, optionally inside a campaign.
 */
function queryVariant(Tenant $tenant, SocialAccount $account, string $contentType, ?Campaign $campaign = null, string $providerPostId = 'x-1'): PostVariant
{
    return PostVariant::factory()->create([
        'post_id' => Post::factory()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign?->id,
        ])->id,
        'social_account_id' => $account->id,
        'provider' => $account->provider,
        'status' => PostVariantStatus::Published->value,
        'provider_post_id' => $providerPostId,
        'platform_specific' => ['content_type' => $contentType],
    ]);
}

function seedMetric(
    Tenant $tenant,
    SocialAccount $account,
    MetricType $metric,
    int $value,
    string $at,
    ?int $variantId = null,
    ?string $subtype = null,
): AnalyticsMetric {
    $start = Carbon::parse($at);

    return AnalyticsMetric::create([
        'tenant_id' => $tenant->id,
        'social_account_id' => $account->id,
        'post_variant_id' => $variantId,
        'provider' => $account->provider->value,
        'metric_type' => $metric->value,
        'metric_subtype' => $subtype,
        'period_start' => $start,
        'period_end' => $start->copy()->addDay(),
        'value' => $value,
        'recorded_at' => now(),
    ]);
}

function defaultRange(?string $from = null, ?string $to = null): AnalyticsFilters
{
    return AnalyticsFilters::make([
        'from' => $from ?? '2026-09-01',
        'to' => $to ?? '2026-09-30',
    ]);
}

it('flags metrics that two platforms do not measure the same way', function () {
    $tenant = queryTenant();
    $facebook = queryAccount($tenant, SocialPlatform::Facebook);
    $youtube = queryAccount($tenant, SocialPlatform::YouTube);

    seedMetric($tenant, $facebook, MetricType::Views, 100, '2026-09-10');
    seedMetric($tenant, $youtube, MetricType::Views, 500, '2026-09-10');
    seedMetric($tenant, $facebook, MetricType::Likes, 10, '2026-09-10');
    seedMetric($tenant, $youtube, MetricType::Likes, 40, '2026-09-10');

    $totals = app(AnalyticsQueryService::class)->headlineTotals(defaultRange());

    $views = $totals['comparability']['metrics']['views'];

    expect($views['comparable'])->toBeFalse()
        ->and($views['status'])->toBe('not_comparable')
        ->and($views['sum_is_meaningful'])->toBeFalse()
        ->and($views['reported_by'])->toEqualCanonicalizing(['facebook', 'youtube'])
        ->and(array_keys($views['definitions']))->toEqualCanonicalizing(['facebook', 'youtube'])
        ->and($totals['comparability']['metrics']['likes']['comparable'])->toBeTrue()
        ->and($totals['comparability']['incomparable_metrics'])->toContain('views')
        ->and($totals['per_platform']['facebook']['views'])->toBe(100)
        ->and($totals['per_platform']['youtube']['views'])->toBe(500);

    $comparison = app(AnalyticsQueryService::class)->platformComparison(defaultRange());

    expect($comparison['combined']['views']['is_meaningful'])->toBeFalse()
        ->and($comparison['combined']['views']['value'])->toBeNull()
        ->and($comparison['combined']['views']['raw_sum'])->toBe(600)
        ->and($comparison['combined']['likes']['is_meaningful'])->toBeTrue()
        ->and($comparison['combined']['likes']['value'])->toBe(50)
        ->and($comparison['warnings'])->not->toBeEmpty();
});

it('reports which denominator the engagement rate used', function () {
    $tenant = queryTenant();
    $account = queryAccount($tenant, SocialPlatform::Facebook);

    seedMetric($tenant, $account, MetricType::Likes, 60, '2026-09-10');
    seedMetric($tenant, $account, MetricType::Comments, 40, '2026-09-10');
    seedMetric($tenant, $account, MetricType::Reach, 1000, '2026-09-10');

    $withReach = app(AnalyticsQueryService::class)->headlineTotals(defaultRange());

    expect($withReach['engagements'])->toBe(100)
        ->and($withReach['engagement_basis'])->toBe('components')
        ->and($withReach['engagement_rate']['denominator'])->toBe('reach')
        ->and($withReach['engagement_rate']['denominator_value'])->toBe(1000)
        ->and($withReach['engagement_rate']['value'])->toBe(0.1);

    $followers = new AnalyticsFilters(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-08-31'),
    );

    AnalyticsMetric::query()->delete();
    seedMetric($tenant, $account, MetricType::Likes, 60, '2026-08-10');
    seedMetric($tenant, $account, MetricType::Followers, 2000, '2026-08-10');

    $withoutReach = app(AnalyticsQueryService::class)->headlineTotals($followers);

    expect($withoutReach['engagement_rate']['denominator'])->toBe('followers')
        ->and($withoutReach['engagement_rate']['denominator_value'])->toBe(2000)
        ->and($withoutReach['engagement_rate']['value'])->toBe(0.03)
        ->and($withoutReach['engagement_rate']['label'])->toContain('no reach was reported');
});

it('honours the date range, platform, account, content type and campaign filters', function () {
    $tenant = queryTenant();
    $facebook = queryAccount($tenant, SocialPlatform::Facebook);
    $instagram = queryAccount($tenant, SocialPlatform::Instagram);
    $other = queryAccount($tenant, SocialPlatform::TikTok);

    $campaign = Campaign::factory()->create(['tenant_id' => $tenant->id]);

    $video = queryVariant($tenant, $facebook, 'video', $campaign, 'post-video');
    $image = queryVariant($tenant, $instagram, 'image', null, 'post-image');

    seedMetric($tenant, $facebook, MetricType::Likes, 10, '2026-09-10', $video->id);
    seedMetric($tenant, $facebook, MetricType::Likes, 500, '2026-08-10', $video->id);
    seedMetric($tenant, $instagram, MetricType::Likes, 20, '2026-09-10', $image->id);
    seedMetric($tenant, $other, MetricType::Likes, 7, '2026-09-10', $video->id);

    $service = app(AnalyticsQueryService::class);

    $all = $service->headlineTotals(defaultRange());
    expect($all['totals']['likes'])->toBe(37);

    $byDate = $service->headlineTotals(defaultRange('2026-08-01', '2026-08-31'));
    expect($byDate['totals']['likes'])->toBe(500);

    $byPlatform = $service->headlineTotals(AnalyticsFilters::make([
        'from' => '2026-09-01', 'to' => '2026-09-30', 'platforms' => ['facebook'],
    ]));
    expect($byPlatform['totals']['likes'])->toBe(10);

    $byAccount = $service->headlineTotals(AnalyticsFilters::make([
        'from' => '2026-09-01', 'to' => '2026-09-30', 'account_ids' => [$instagram->id],
    ]));
    expect($byAccount['totals']['likes'])->toBe(20);

    $byContent = $service->headlineTotals(AnalyticsFilters::make([
        'from' => '2026-09-01', 'to' => '2026-09-30', 'content_types' => ['image'],
    ]));
    expect($byContent['totals']['likes'])->toBe(20);

    $byCampaign = $service->headlineTotals(AnalyticsFilters::make([
        'from' => '2026-09-01', 'to' => '2026-09-30', 'campaign_ids' => [$campaign->id],
    ]));
    expect($byCampaign['totals']['likes'])->toBe(17);

    $top = $service->topPosts(defaultRange(), 'likes');
    expect($top['posts'])->toHaveCount(2)
        ->and($top['posts'][0]['value'])->toBe(20);

    $byContentTop = $service->topPosts(
        AnalyticsFilters::make(['from' => '2026-09-01', 'to' => '2026-09-30', 'content_types' => ['video']]),
        'likes',
    );
    expect($byContentTop['posts'])->toHaveCount(1)
        ->and($byContentTop['posts'][0]['value'])->toBe(17)
        ->and($byContentTop['posts'][0]['content_type'])->toBe('video');

    $performance = $service->contentTypePerformance(defaultRange());
    expect($performance['content_types']['video']['facebook']['likes'])->toBe(10)
        ->and($performance['content_types']['video']['tiktok']['likes'])->toBe(7)
        ->and($performance['content_types']['image']['instagram']['likes'])->toBe(20);

    $frequency = $service->postingFrequency(defaultRange(), 'month');
    expect($frequency['total_posts'])->toBe(2);
});

it('never lets one tenant see another tenant metrics', function () {
    $mine = queryTenant();
    $myAccount = queryAccount($mine, SocialPlatform::Facebook);
    seedMetric($mine, $myAccount, MetricType::Likes, 42, '2026-09-10');

    $theirs = Tenant::factory()->create();
    TenantContext::set($theirs);

    $theirAccount = SocialAccount::factory()->create([
        'tenant_id' => $theirs->id,
        'provider' => SocialPlatform::Facebook,
        'status' => SocialAccountStatus::Connected->value,
    ]);
    seedMetric($theirs, $theirAccount, MetricType::Likes, 4242, '2026-09-10');

    $theirsTotals = app(AnalyticsQueryService::class)->headlineTotals(defaultRange());

    expect($theirsTotals['totals']['likes'])->toBe(4242)
        ->and($theirsTotals['per_platform']['facebook']['likes'])->toBe(4242);

    TenantContext::set($mine);

    $mineTotals = app(AnalyticsQueryService::class)->headlineTotals(defaultRange());

    expect($mineTotals['totals']['likes'])->toBe(42)
        ->and($mineTotals['per_platform']['facebook']['likes'])->toBe(42)
        ->and(AnalyticsMetric::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('reports a series and follower growth from the stored readings', function () {
    $tenant = queryTenant();
    $account = queryAccount($tenant, SocialPlatform::Instagram);

    seedMetric($tenant, $account, MetricType::Views, 100, '2026-09-10');
    seedMetric($tenant, $account, MetricType::Views, 150, '2026-09-11');
    seedMetric($tenant, $account, MetricType::Followers, 1000, '2026-09-10');
    seedMetric($tenant, $account, MetricType::Followers, 1075, '2026-09-11');

    $service = app(AnalyticsQueryService::class);

    $series = $service->metricSeries(defaultRange(), MetricType::Views, 'day');

    expect($series['points'])->toHaveCount(2)
        ->and($series['total'])->toBe(250)
        ->and($series['comparability']['metrics']['views']['reported_by'])->toBe(['instagram'])
        ->and($series['comparability']['platforms'])->toBe(['instagram']);

    $growth = $service->followerGrowth(defaultRange(), 'day');

    expect($growth['points'])->toHaveCount(2)
        ->and($growth['points'][0]['followers'])->toBe(1000)
        ->and($growth['points'][1]['followers'])->toBe(1075)
        ->and($growth['points'][1]['change'])->toBe(75);

    $totals = $service->headlineTotals(defaultRange());
    expect($totals['totals']['followers'])->toBe(1075)
        ->and($totals['totals']['follower_growth'])->toBe(75);
});

it('leaves unattributed post readings out of totals so they are not double counted', function () {
    $tenant = queryTenant();
    $account = queryAccount($tenant, SocialPlatform::Facebook);

    seedMetric($tenant, $account, MetricType::Likes, 10, '2026-09-10');
    seedMetric($tenant, $account, MetricType::Comments, 7, '2026-09-10', null, 'unattributed:post:foreign-1');

    $totals = app(AnalyticsQueryService::class)->headlineTotals(defaultRange());

    expect($totals['totals']['likes'])->toBe(10)
        ->and($totals['totals'])->not->toHaveKey('comments')
        ->and($totals['engagements'])->toBe(10);
});
