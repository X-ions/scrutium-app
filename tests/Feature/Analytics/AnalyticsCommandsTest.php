<?php

declare(strict_types=1);

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Jobs\Analytics\SyncAccountAnalyticsJob;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());

afterEach(fn () => TenantContext::forget());

it('does not attempt partitioning on a driver that has none', function () {
    $this->artisan('socialhub:analytics:partitions --initialize')
        ->expectsOutputToContain('partitioning is MySQL-only')
        ->assertExitCode(0);

    expect(DB::connection()->getDriverName())->toBe('sqlite');
});

it('queues one staggered job per connected account', function () {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);

    foreach (range(1, 3) as $index) {
        SocialAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'provider' => SocialPlatform::Facebook,
            'status' => SocialAccountStatus::Connected->value,
        ]);
    }

    SocialAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => SocialPlatform::Facebook,
        'status' => SocialAccountStatus::Revoked->value,
    ]);

    $this->artisan('socialhub:analytics:sync')->assertExitCode(0);

    Queue::assertPushed(SyncAccountAnalyticsJob::class, 3);
    Queue::assertPushed(SyncAccountAnalyticsJob::class, fn (SyncAccountAnalyticsJob $job): bool => $job->lookbackDays === 7);
});

it('sends every job out immediately when staggering is switched off', function () {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);

    SocialAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => SocialPlatform::Instagram,
        'status' => SocialAccountStatus::Connected->value,
    ]);

    $this->artisan('socialhub:analytics:sync --no-stagger --days=1')->assertExitCode(0);

    Queue::assertPushed(SyncAccountAnalyticsJob::class, fn (SyncAccountAnalyticsJob $job): bool => $job->lookbackDays === 1);
});
