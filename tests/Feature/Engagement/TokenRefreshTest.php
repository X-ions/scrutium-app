<?php

declare(strict_types=1);

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\TokenExpiredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Models\SocialAccountToken;
use App\Models\SocialHubNotification;
use App\Services\Engagement\TokenRefreshService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Engagement\FakeEngagementProvider;

require_once __DIR__.'/../../Support/engagement_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    useFakeEngagementProviders();
    Cache::clear();
});

afterEach(fn () => TenantContext::forget());

function tokenRefresh(): TokenRefreshService
{
    return app(TokenRefreshService::class);
}

it('refreshes a token that is about to expire', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    $token = tokenExpiringIn($account);
    $originalRefreshToken = $token->refresh_token;

    $summary = tokenRefresh()->refreshExpiring();

    expect($summary['refreshed'])->toBe(1)
        ->and($summary['revoked'])->toBe(0)
        ->and($token->refresh()->first()->access_token)->toBe('refreshed-access-token')
        // A provider that rotates refresh tokens returns a new one; we store it.
        ->and($token->refresh()->first()->refresh_token)->toBe('refreshed-refresh-token')
        ->and($originalRefreshToken)->not->toBe('refreshed-refresh-token');
});

it('leaves a token that is not yet in the window alone', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    $token = SocialAccountToken::factory()->create([
        'social_account_id' => $account->getKey(),
        'expires_at' => now()->addDays(30),
    ]);

    $summary = tokenRefresh()->refreshExpiring();

    expect($summary['refreshed'])->toBe(0)
        ->and(FakeEngagementProvider::$refreshCalls)->toBe(0)
        ->and($token->refresh()->first()->access_token)->toBe($token->refresh()->first()->access_token);
});

it('keeps the previous refresh token when the provider does not return one', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    $token = tokenExpiringIn($account);
    $original = $token->refresh_token;

    FakeEngagementProvider::refreshFor($account, new \App\Services\Social\Data\TokenSet(
        accessToken: 'new-access',
        refreshToken: null,
        expiresIn: 7200,
    ));

    tokenRefresh()->refreshExpiring();

    $stored = $token->refresh()->first();

    expect($stored->access_token)->toBe('new-access')
        // Providers that do not rotate simply omit it; dropping the stored value
        // would make the next refresh impossible.
        ->and($stored->refresh_token)->toBe($original);
});

// 13. Revocation is terminal

it('marks the account revoked and notifies once on invalid_grant, without a retry loop', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);

    FakeEngagementProvider::refreshFor($account, new TokenRevokedException(
        'facebook',
        \App\Services\Social\Data\UserFacingError::make(
            'token_revoked',
            'This Facebook Page access was revoked. Reconnect the account to continue.',
            'OAuth error: invalid_grant.',
            false,
        ),
    ));

    $summary = tokenRefresh()->refreshExpiring();

    expect($summary['revoked'])->toBe(1)
        ->and($account->refresh()->status)->toBe(SocialAccountStatus::Revoked)
        ->and(SocialHubNotification::query()->where('type', 'social_account.token_revoked')->count())->toBe(1);

    // Running again must not retry the credential or raise a second alert.
    $second = tokenRefresh()->refreshExpiring();

    expect($second['revoked'])->toBe(0)
        ->and($second['refreshed'])->toBe(0)
        ->and(SocialHubNotification::query()->where('type', 'social_account.token_revoked')->count())->toBe(1)
        ->and($account->refresh()->status)->toBe(SocialAccountStatus::Revoked);
});

it('only asks the provider once for a revoked credential', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);
    FakeEngagementProvider::refreshFor($account, new TokenRevokedException('facebook'));

    tokenRefresh()->refreshExpiring();

    expect(FakeEngagementProvider::$refreshCalls)->toBe(1);

    tokenRefresh()->refreshExpiring();
    tokenRefresh()->refreshExpiring();

    // A revoked account is not `connected`, so it is not even a sweep candidate.
    expect(FakeEngagementProvider::$refreshCalls)->toBe(1);
});

it('marks the account expired when the credentials cannot be renewed', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);

    FakeEngagementProvider::refreshFor($account, new TokenExpiredException('facebook'));

    $summary = tokenRefresh()->refreshExpiring();

    expect($summary['expired'])->toBe(1)
        ->and($account->refresh()->status)->toBe(SocialAccountStatus::Expired)
        ->and(SocialHubNotification::query()->where('type', 'social_account.token_expired')->count())->toBe(1);
});

it('expires an account that has no refresh token instead of looping', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    SocialAccountToken::factory()->create([
        'social_account_id' => $account->getKey(),
        'refresh_token' => null,
        'expires_at' => now()->addHours(2),
    ]);

    $summary = tokenRefresh()->refreshExpiring();

    expect($summary['expired'])->toBe(1)
        ->and(FakeEngagementProvider::$refreshCalls)->toBe(0)
        ->and($account->refresh()->status)->toBe(SocialAccountStatus::Expired);
});

it('reports a transient provider error without marking the account', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);

    FakeEngagementProvider::refreshFor($account, new ProviderApiException('facebook', 503, null));

    $summary = tokenRefresh()->refreshExpiring();

    // A 5xx is not a credential problem; the account stays connected so the next
    // sweep can try again.
    expect($summary['failed'])->toBe(1)
        ->and($summary['revoked'])->toBe(0)
        ->and($account->refresh()->status)->toBe(SocialAccountStatus::Connected)
        ->and(SocialHubNotification::query()->count())->toBe(0);
});

// Concurrency

it('takes a sweep lock so two overlapping runs do not both refresh', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);

    // Simulate a second scheduler tick that grabbed the lock first.
    $held = Cache::lock(TokenRefreshService::SWEEP_LOCK, TokenRefreshService::SWEEP_LOCK_SECONDS);
    $held->get();

    $summary = tokenRefresh()->refreshExpiring();

    expect($summary['lock_held'])->toBeTrue()
        ->and($summary['refreshed'])->toBe(0)
        ->and(FakeEngagementProvider::$refreshCalls)->toBe(0);

    $held->release();

    expect(tokenRefresh()->refreshExpiring()['refreshed'])->toBe(1);
});

it('releases the sweep lock even when a refresh throws', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);
    FakeEngagementProvider::refreshFor($account, new RuntimeException('unexpected'));

    tokenRefresh()->refreshExpiring();

    // A poisoned account must not wedge every later sweep.
    expect(Cache::lock(TokenRefreshService::SWEEP_LOCK, 1)->get())->toBeTrue();
});

it('skips an account another worker is already refreshing', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);

    $held = Cache::lock(TokenRefreshService::ACCOUNT_LOCK_PREFIX.$account->getKey(), 60);
    $held->get();

    expect(tokenRefresh()->refresh($account))->toBe('skipped')
        ->and(FakeEngagementProvider::$refreshCalls)->toBe(0);

    $held->release();

    expect(tokenRefresh()->refresh($account))->toBe('refreshed');
});

it('is safe to run repeatedly with no accumulating side effects', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account);

    tokenRefresh()->refreshExpiring();
    tokenRefresh()->refreshExpiring();
    tokenRefresh()->refreshExpiring();

    expect(SocialHubNotification::query()->count())->toBe(0)
        ->and($account->refresh()->status)->toBe(SocialAccountStatus::Connected);
});

// Expiring-soon warnings

it('warns once about a token that is about to lapse', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account, '+6 hours');

    $warned = tokenRefresh()->warnExpiring(24);

    expect($warned)->toHaveCount(1)
        ->and(SocialHubNotification::query()->where('type', 'social_account.token_expiring')->count())->toBe(1);

    // A second warning in the same hour would be noise, not information.
    tokenRefresh()->warnExpiring(24);

    expect(SocialHubNotification::query()->where('type', 'social_account.token_expiring')->count())->toBe(1);
});

it('does not warn about a token that expires in a week', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    tokenExpiringIn($account, '+7 days');

    expect(tokenRefresh()->warnExpiring(24))->toBe([])
        ->and(SocialHubNotification::query()->count())->toBe(0);
});

it('keeps every credential out of the expiring notification', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    SocialAccountToken::factory()->create([
        'social_account_id' => $account->getKey(),
        'access_token' => 'leaky-access-token',
        'refresh_token' => 'leaky-refresh-token',
        'expires_at' => now()->addHours(3),
    ]);

    tokenRefresh()->warnExpiring(24);

    $encoded = json_encode(SocialHubNotification::query()->first()->toArray());

    expect($encoded)->not->toContain('leaky-access-token')
        ->not->toContain('leaky-refresh-token');
});
