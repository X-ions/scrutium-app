<?php

declare(strict_types=1);

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenExpiredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Jobs\Analytics\AggregateSnapshotsJob;
use App\Jobs\Analytics\SyncAccountAnalyticsJob;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\SocialAccount;
use App\Models\SocialHubNotification;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsIngestService;
use App\Services\Analytics\SnapshotAggregator;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\MetricSample;
use App\Services\Social\SocialProviderRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Analytics\FakeAnalyticsProvider;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());

afterEach(fn () => TenantContext::forget());

function jobTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);

    TenantContext::set($tenant);

    return $tenant;
}

function jobAccount(Tenant $tenant, SocialPlatform $platform): SocialAccount
{
    return SocialAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => $platform,
        'status' => SocialAccountStatus::Connected->value,
    ]);
}

/**
 * Every provider key resolves to the fake, so the job reaches the fake without
 * touching the real provider implementations.
 */
function useFakeProvider(): void
{
    FakeAnalyticsProvider::reset();

    app()->instance(SocialProviderRegistry::class, new SocialProviderRegistry(
        app(),
        array_fill_keys(
            array_map(static fn (SocialPlatform $platform): string => $platform->value, SocialPlatform::cases()),
            FakeAnalyticsProvider::class,
        ),
    ));
}

function runSync(int $accountId): void
{
    (new SyncAccountAnalyticsJob($accountId))->handle(
        app(SocialProviderRegistry::class),
        app(AnalyticsIngestService::class),
    );
}

function successfulBatch(SocialPlatform $platform, int $views = 100): AnalyticsBatch
{
    $start = Carbon::now()->subDay()->startOfDay();

    return new AnalyticsBatch(
        provider: $platform->value,
        periodStart: $start,
        periodEnd: Carbon::now(),
        accountMetrics: [new MetricSample(
            metric: 'views',
            value: $views,
            periodStart: $start->toIso8601String(),
        )],
    );
}

it('lets a throttled account fail without stopping another account', function () {
    Queue::fake();

    $tenant = jobTenant();
    $throttled = jobAccount($tenant, SocialPlatform::Facebook);
    $healthy = jobAccount($tenant, SocialPlatform::Instagram);

    useFakeProvider();

    FakeAnalyticsProvider::for($throttled, new RateLimitException('facebook', 120));
    FakeAnalyticsProvider::for($healthy, successfulBatch(SocialPlatform::Instagram, 250));

    runSync($throttled->id);
    runSync($healthy->id);

    expect($throttled->refresh()->status)->toBe(SocialAccountStatus::Connected)
        ->and($healthy->refresh()->status)->toBe(SocialAccountStatus::Connected)
        ->and(FakeAnalyticsProvider::$calledAccounts)->toBe([$throttled->id, $healthy->id])
        ->and(AnalyticsMetric::query()->where('social_account_id', $throttled->id)->count())->toBe(0)
        ->and(AnalyticsMetric::query()->where('social_account_id', $healthy->id)->first()->value)->toBe(250);
});

it('skips an account whose network does not offer analytics', function () {
    Queue::fake();

    $tenant = jobTenant();
    $account = jobAccount($tenant, SocialPlatform::Pinterest);

    useFakeProvider();

    FakeAnalyticsProvider::for(
        $account,
        UnsupportedCapabilityException::for('analytics', 'pinterest', 'Pinterest'),
    );

    runSync($account->id);

    expect($account->refresh()->status)->toBe(SocialAccountStatus::Connected)
        ->and(AnalyticsMetric::query()->count())->toBe(0)
        ->and(SocialHubNotification::query()->count())->toBe(0);
});

it('marks a revoked token, notifies the tenant and does not loop', function () {
    Queue::fake();

    $tenant = jobTenant();
    $account = jobAccount($tenant, SocialPlatform::LinkedIn);

    useFakeProvider();

    FakeAnalyticsProvider::for($account, new TokenRevokedException('linkedin'));

    runSync($account->id);

    $account->refresh();

    expect($account->status)->toBe(SocialAccountStatus::Revoked)
        ->and($account->isActive())->toBeFalse()
        ->and((string) $account->last_error)->toContain('revoked')
        ->and(SocialHubNotification::query()->count())->toBe(1)
        ->and(SocialHubNotification::query()->first()->type)->toBe('social_account.token_revoked')
        ->and(SocialHubNotification::query()->first()->tenant_id)->toBe($tenant->id);

    runSync($account->id);

    expect(SocialHubNotification::query()->count())->toBe(1)
        ->and(FakeAnalyticsProvider::$calls)->toBe(1);
});

it('marks an expired token without retrying it', function () {
    Queue::fake();

    $tenant = jobTenant();
    $account = jobAccount($tenant, SocialPlatform::X);

    useFakeProvider();

    FakeAnalyticsProvider::for($account, new TokenExpiredException('x'));

    runSync($account->id);

    expect($account->refresh()->status)->toBe(SocialAccountStatus::Expired)
        ->and(SocialHubNotification::query()->first()->type)->toBe('social_account.token_expired');
});

it('stores the batch and queues the aggregation after a successful sync', function () {
    Queue::fake();

    $tenant = jobTenant();
    $account = jobAccount($tenant, SocialPlatform::Facebook);

    useFakeProvider();

    FakeAnalyticsProvider::for($account, successfulBatch(SocialPlatform::Facebook, 321));

    runSync($account->id);

    expect(AnalyticsMetric::query()->where('social_account_id', $account->id)->first()->value)->toBe(321)
        ->and(AnalyticsMetric::query()->where('metric_type', 'followers')->exists())->toBeTrue()
        ->and(Queue::assertPushed(AggregateSnapshotsJob::class));
});

it('aggregates snapshots from the queued aggregation job', function () {
    $tenant = jobTenant();
    $account = jobAccount($tenant, SocialPlatform::Facebook);

    AnalyticsMetric::create([
        'tenant_id' => $tenant->id,
        'social_account_id' => $account->id,
        'provider' => 'facebook',
        'metric_type' => 'views',
        'period_start' => Carbon::now()->subDay()->startOfDay(),
        'period_end' => Carbon::now(),
        'value' => 77,
        'recorded_at' => now(),
    ]);

    (new AggregateSnapshotsJob($tenant->id, $account->id))->handle(app(SnapshotAggregator::class));

    expect(AnalyticsSnapshot::query()->where('social_account_id', $account->id)->count())->toBeGreaterThan(0);
});
