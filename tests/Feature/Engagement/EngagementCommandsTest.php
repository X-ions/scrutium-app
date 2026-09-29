<?php

declare(strict_types=1);

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Jobs\Engagement\CommentSyncJob;
use App\Models\Comment;
use App\Models\SocialHubNotification;
use App\Models\WebhookEvent;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Engagement\FakeEngagementProvider;

require_once __DIR__.'/../../Support/engagement_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    useFakeEngagementProviders();
    Cache::clear();
});

afterEach(fn () => TenantContext::forget());

describe('socialhub:comments:sync', function (): void {
    it('queues one job per connected account', function (): void {
        Queue::fake();

        $tenant = engagementTenant();
        engagementAccount($tenant, SocialPlatform::Facebook);
        engagementAccount($tenant, SocialPlatform::Instagram);
        engagementAccount($tenant, SocialPlatform::X);

        $this->artisan('socialhub:comments:sync')
            ->assertSuccessful();

        Queue::assertPushed(CommentSyncJob::class, 3);
    });

    it('skips an account that is not connected', function (): void {
        Queue::fake();

        $tenant = engagementTenant();
        engagementAccount($tenant, SocialPlatform::Facebook);
        engagementAccount($tenant, SocialPlatform::Instagram, [
            'status' => SocialAccountStatus::Revoked->value,
        ]);

        $this->artisan('socialhub:comments:sync')->assertSuccessful();

        Queue::assertPushed(CommentSyncJob::class, 1);
    });

    it('staggers jobs so a hundred accounts do not hit a provider at once', function (): void {
        Queue::fake();

        $tenant = engagementTenant();

        foreach (range(1, 5) as $index) {
            engagementAccount($tenant, SocialPlatform::Facebook, [
                'provider_account_id' => 'page-'.$index,
            ]);
        }

        $this->artisan('socialhub:comments:sync')->assertSuccessful();

        $delays = [];

        Queue::assertPushed(CommentSyncJob::class, function (CommentSyncJob $job) use (&$delays): bool {
            $delays[] = $job->delay;

            return true;
        });

        expect($delays)->toHaveCount(5)
            ->and(array_unique(array_map('intval', $delays)))->not->toHaveCount(1);
    });

    it('restricts the sweep to one account when asked', function (): void {
        Queue::fake();

        $tenant = engagementTenant();
        $wanted = engagementAccount($tenant, SocialPlatform::Facebook);
        engagementAccount($tenant, SocialPlatform::Instagram);

        $this->artisan('socialhub:comments:sync', ['--account' => $wanted->getKey()])
            ->assertSuccessful();

        Queue::assertPushed(CommentSyncJob::class, fn (CommentSyncJob $job): bool => $job->socialAccountId === (int) $wanted->getKey());
    });

    it('completes with a connected account and no comments', function (): void {
        $tenant = engagementTenant();
        $account = engagementAccount($tenant, SocialPlatform::Facebook);
        $variant = publishedVariant($tenant, $account, 'post-1');

        FakeEngagementProvider::commentsFor($account, [providerComment($variant, 'c-1', 'Hello from the command')]);

        $this->artisan('socialhub:comments:sync', ['--sync' => true, '--no-stagger' => true])
            ->assertSuccessful();

        expect(Comment::query()->count())->toBe(1)
        // One account, one notification.
            ->and(SocialHubNotification::query()->count())->toBe(1);
    });
});

describe('socialhub:tokens:refresh', function (): void {
    it('refreshes an expiring token', function (): void {
        $tenant = engagementTenant();
        $account = engagementAccount($tenant, SocialPlatform::Facebook);

        tokenExpiringIn($account);

        $this->artisan('socialhub:tokens:refresh')->assertSuccessful();

        expect(FakeEngagementProvider::$refreshCalls)->toBe(1);
    });

    it('warns without refreshing in warn-only mode', function (): void {
        $tenant = engagementTenant();
        $account = engagementAccount($tenant, SocialPlatform::Facebook);

        tokenExpiringIn($account, '+6 hours');

        $this->artisan('socialhub:tokens:refresh', ['--warn-only' => true])->assertSuccessful();

        expect(FakeEngagementProvider::$refreshCalls)->toBe(0)
            ->and(SocialHubNotification::query()->where('type', 'social_account.token_expiring')->count())->toBe(1);
    });

    it('is a no-op while another sweep holds the lock', function (): void {
        $tenant = engagementTenant();
        $account = engagementAccount($tenant, SocialPlatform::Facebook);

        tokenExpiringIn($account);

        $held = Cache::lock(\App\Services\Engagement\TokenRefreshService::SWEEP_LOCK, 60);
        $held->get();

        $this->artisan('socialhub:tokens:refresh')->assertSuccessful();

        expect(FakeEngagementProvider::$refreshCalls)->toBe(0)
            ->and($account->refresh()->status)->toBe(SocialAccountStatus::Connected);

        $held->release();

        $this->artisan('socialhub:tokens:refresh')->assertSuccessful();

        expect(FakeEngagementProvider::$refreshCalls)->toBe(1);
    });

    it('marks a revoked account and stops asking the provider', function (): void {
        $tenant = engagementTenant();
        $account = engagementAccount($tenant, SocialPlatform::Facebook);

        tokenExpiringIn($account);
        FakeEngagementProvider::refreshFor($account, new \App\Exceptions\Social\TokenRevokedException('facebook'));

        $this->artisan('socialhub:tokens:refresh')->assertSuccessful();
        $this->artisan('socialhub:tokens:refresh')->assertSuccessful();

        expect(FakeEngagementProvider::$refreshCalls)->toBe(1)
            ->and($account->refresh()->status)->toBe(SocialAccountStatus::Revoked)
            ->and(SocialHubNotification::query()->where('type', 'social_account.token_revoked')->count())->toBe(1);
    });
});

describe('socialhub:webhooks:prune', function (): void {
    it('deletes processed events past the retention window', function (): void {
        $tenant = engagementTenant();
        $account = engagementAccount($tenant, SocialPlatform::Facebook, ['provider_account_id' => 'page-1']);

        $old = WebhookEvent::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => 'facebook',
            'event_id' => 'old',
            'processed' => true,
            'created_at' => now()->subDays(60),
        ]);

        $recent = WebhookEvent::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => 'facebook',
            'event_id' => 'recent',
            'processed' => true,
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('socialhub:webhooks:prune', ['--days' => 30])->assertSuccessful();

        expect(WebhookEvent::query()->find($old->getKey()))->toBeNull()
            ->and(WebhookEvent::query()->find($recent->getKey()))->not->toBeNull();
    });

    it('never deletes an unprocessed event, however old it is', function (): void {
        $tenant = engagementTenant();

        $unprocessed = WebhookEvent::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => 'facebook',
            'event_id' => 'owed',
            'processed' => false,
            'created_at' => now()->subYear(),
        ]);

        $this->artisan('socialhub:webhooks:prune', ['--days' => 1])->assertSuccessful();

        // The row is the record of work still owed; deleting it would silently
        // lose an update the network really sent.
        expect(WebhookEvent::query()->find($unprocessed->getKey()))->not->toBeNull();
    });

    it('reports rather than deletes in dry-run mode', function (): void {
        $tenant = engagementTenant();

        $old = WebhookEvent::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => 'facebook',
            'event_id' => 'old',
            'processed' => true,
            'created_at' => now()->subDays(60),
        ]);

        $this->artisan('socialhub:webhooks:prune', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Would delete 1 processed event')
            ->assertSuccessful();

        expect(WebhookEvent::query()->find($old->getKey()))->not->toBeNull();
    });

    it('is a no-op when there is nothing to prune', function (): void {
        $this->artisan('socialhub:webhooks:prune')
            ->expectsOutputToContain('Nothing to prune')
            ->assertSuccessful();
    });
});
