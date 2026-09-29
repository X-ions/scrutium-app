<?php

declare(strict_types=1);

use App\Enums\CommentSyncStatus;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Jobs\Engagement\CommentSyncJob;
use App\Models\Comment;
use App\Models\SocialHubNotification;
use App\Services\Engagement\CommentSyncService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Engagement\FakeEngagementProvider;

require_once __DIR__.'/../../Support/engagement_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    useFakeEngagementProviders();
});

afterEach(fn () => TenantContext::forget());

function runCommentSync(int $accountId): void
{
    (new CommentSyncJob($accountId))->handle(app(CommentSyncService::class));
}

it('upserts comments onto the provider unique key without duplicating on re-sync', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    FakeEngagementProvider::commentsFor($account, [
        providerComment($variant, 'c1', 'First comment'),
        providerComment($variant, 'c2', 'Second comment'),
    ]);

    $first = commentSync()->syncAccount($account);

    expect($first->created)->toBe(2)
        ->and($first->updated)->toBe(0)
        ->and(Comment::query()->count())->toBe(2);

    FakeEngagementProvider::commentsFor($account, [
        providerComment($variant, 'c1', 'First comment, edited'),
        providerComment($variant, 'c2', 'Second comment'),
    ]);

    $second = commentSync()->syncAccount($account);

    expect($second->created)->toBe(0)
        ->and($second->updated)->toBe(1)
        ->and($second->unchanged)->toBe(1)
        ->and(Comment::query()->count())->toBe(2)
        ->and(Comment::query()->where('provider_comment_id', 'c1')->first()->content)->toBe('First comment, edited');
});

it('marks a comment deleted and hidden when the provider reports it', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    FakeEngagementProvider::commentsFor($account, [providerComment($variant, 'c1')]);

    commentSync()->syncAccount($account);

    expect((bool) Comment::query()->where('provider_comment_id', 'c1')->first()->is_deleted)->toBeFalse();

    FakeEngagementProvider::commentsFor($account, [
        providerComment($variant, 'c1', 'Nice work', ['is_deleted' => true]),
    ]);

    commentSync()->syncAccount($account);

    $comment = Comment::query()->where('provider_comment_id', 'c1')->first();

    expect((bool) $comment->is_deleted)->toBeTrue()
        ->and($comment->syncStatus())->toBe(CommentSyncStatus::Deleted);
});

it('marks a comment hidden when the provider reports it', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    FakeEngagementProvider::commentsFor($account, [
        providerComment($variant, 'c1', 'Spam', ['is_hidden' => true]),
    ]);

    commentSync()->syncAccount($account);

    $comment = Comment::query()->where('provider_comment_id', 'c1')->first();

    expect((bool) $comment->is_hidden)->toBeTrue()
        ->and($comment->syncStatus())->toBe(CommentSyncStatus::Failed);
});

it('refreshes like and reply counters on every sync', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    $first = providerComment($variant, 'c1', 'Text');
    $first = new \App\Services\Social\Data\ProviderComment(
        provider: $first->provider,
        providerCommentId: $first->providerCommentId,
        providerPostId: $first->providerPostId,
        content: $first->content,
        likeCount: 3,
        replyCount: 1,
        createdAt: $first->createdAt,
    );

    FakeEngagementProvider::commentsFor($account, [$first]);
    commentSync()->syncAccount($account);

    expect((int) Comment::query()->where('provider_comment_id', 'c1')->first()->like_count)->toBe(3);

    $second = new \App\Services\Social\Data\ProviderComment(
        provider: $first->provider,
        providerCommentId: $first->providerCommentId,
        providerPostId: $first->providerPostId,
        content: $first->content,
        likeCount: 41,
        replyCount: 7,
        createdAt: $first->createdAt,
    );

    FakeEngagementProvider::commentsFor($account, [$second]);
    commentSync()->syncAccount($account);

    $comment = Comment::query()->where('provider_comment_id', 'c1')->first();

    expect((int) $comment->like_count)->toBe(41)
        ->and((int) $comment->reply_count)->toBe(7);
});

it('links a reply to its parent when the parent is already known', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    $parent = providerComment($variant, 'parent-1');
    $child = new \App\Services\Social\Data\ProviderComment(
        provider: $parent->provider,
        providerCommentId: 'child-1',
        providerPostId: $parent->providerPostId,
        content: 'Agreed',
        parentProviderCommentId: 'parent-1',
    );

    FakeEngagementProvider::commentsFor($account, [$parent, $child]);
    commentSync()->syncAccount($account);

    $childRow = Comment::query()->where('provider_comment_id', 'child-1')->first();

    expect($childRow->parent_comment_id)->toBe(Comment::query()->where('provider_comment_id', 'parent-1')->first()->getKey())
        ->and($childRow->isThreadRoot())->toBeFalse();
});

it('skips a comment on a post SocialHub did not publish instead of guessing', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    // A comment whose post id matches nothing we published.
    $orphan = new \App\Services\Social\Data\ProviderComment(
        provider: 'facebook',
        providerCommentId: 'orphan-1',
        providerPostId: 'a-post-we-never-published',
        content: 'Hello from the outside',
    );

    FakeEngagementProvider::commentsFor($account, [$orphan]);

    $result = commentSync()->syncAccount($account);

    expect($result->created)->toBe(0)
        ->and($result->skipped)->toBe(['a-post-we-never-published'])
        ->and(Comment::query()->count())->toBe(0);
});

it('refuses to attribute a comment to another accounts post', function (): void {
    $tenant = engagementTenant();
    $accountA = engagementAccount($tenant, SocialPlatform::Facebook);
    $accountB = engagementAccount($tenant, SocialPlatform::Facebook);

    $variantA = publishedVariant($tenant, $accountA, 'post-from-a');

    $spoof = new \App\Services\Social\Data\ProviderComment(
        provider: 'facebook',
        providerCommentId: 'spoof-1',
        providerPostId: 'post-from-a',
        content: 'Trying to land on another account',
    );

    FakeEngagementProvider::commentsFor($accountB, [$spoof]);

    $result = commentSync()->syncAccount($accountB);

    expect($result->created)->toBe(0)
        ->and(Comment::query()->count())->toBe(0)
        ->and(Comment::query()->where('post_variant_id', $variantA->getKey())->count())->toBe(0);
});

it('stops at the per-account comment cap and says so', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    $comments = [];

    for ($i = 0; $i < 10; $i++) {
        $comments[] = providerComment($variant, 'c'.$i);
    }

    FakeEngagementProvider::commentsFor($account, $comments);

    $result = commentSync()->syncAccount($account, maxComments: 4);

    expect($result->created)->toBe(4)
        ->and($result->skipped)->toBe(['page_limit_reached']);
});

it('skips a network that has no comment API at all', function (): void {
    $tenant = engagementTenant();

    // Pinterest is the one platform in the verified matrix with no comment API.
    $account = engagementAccount($tenant, SocialPlatform::Pinterest);

    $result = commentSync()->syncAccount($account);

    expect($result->failed())->toBeTrue()
        ->and($result->failureCode)->toBe('unsupported_capability')
        ->and(Comment::query()->count())->toBe(0);
});

it('does not call a provider for an account that is not connected', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook, [
        'status' => SocialAccountStatus::Revoked->value,
    ]);

    $result = commentSync()->syncAccount($account);

    expect($result->failureCode)->toBe('account_inactive')
        ->and(FakeEngagementProvider::$commentCalls)->toBe(0);
});

// 12. One account's failure does not affect another

it('lets one account fail without affecting another accounts sync', function (): void {
    $tenant = engagementTenant();
    $failing = engagementAccount($tenant, SocialPlatform::Facebook);
    $healthy = engagementAccount($tenant, SocialPlatform::Instagram);

    $failingVariant = publishedVariant($tenant, $failing, 'post-fb');
    $healthyVariant = publishedVariant($tenant, $healthy, 'post-ig');

    FakeEngagementProvider::commentsFor($failing, new RateLimitException('facebook', 120));
    FakeEngagementProvider::commentsFor($healthy, [
        providerComment($healthyVariant, 'ig-1', 'Instagram comment'),
    ]);

    $first = commentSync()->syncAccount($failing);
    $second = commentSync()->syncAccount($healthy);

    expect($first->failed())->toBeTrue()
        ->and($first->failureCode)->toBe('rate_limited')
        ->and($second->failed())->toBeFalse()
        ->and($second->created)->toBe(1)
        ->and(Comment::query()->where('provider_comment_id', 'ig-1')->exists())->toBeTrue()
        ->and($failing->refresh()->status)->toBe(SocialAccountStatus::Connected)
        ->and(Comment::query()->where('post_variant_id', $failingVariant->getKey())->count())->toBe(0);
});

it('lets a throttled account fail while the other accounts jobs still run', function (): void {
    Queue::fake();

    $tenant = engagementTenant();
    $throttled = engagementAccount($tenant, SocialPlatform::Facebook);
    $healthy = engagementAccount($tenant, SocialPlatform::X);

    $throttledVariant = publishedVariant($tenant, $throttled, 'post-fb');
    $healthyVariant = publishedVariant($tenant, $healthy, 'post-x');

    $stale = now()->subWeek();
    $throttled->forceFill(['last_synced_at' => $stale])->save();

    FakeEngagementProvider::commentsFor($throttled, new RateLimitException('facebook', 60));
    FakeEngagementProvider::commentsFor($healthy, [providerComment($healthyVariant, 'x-1', 'X comment')]);

    runCommentSync((int) $throttled->getKey());
    runCommentSync((int) $healthy->getKey());

    expect(Comment::query()->where('provider_comment_id', 'x-1')->exists())->toBeTrue()
        ->and(Comment::query()->where('post_variant_id', $throttledVariant->getKey())->count())->toBe(0)
        // The healthy account records progress; the throttled one does not, so a
        // failed sync is never mistaken for a successful one.
        ->and($healthy->refresh()->last_synced_at->isAfter($stale))->toBeTrue()
        ->and($throttled->refresh()->last_synced_at->toDateTimeString())->toBe($stale->toDateTimeString());
});

it('lets a provider 5xx on one account pass through without poisoning the other', function (): void {
    $tenant = engagementTenant();
    $broken = engagementAccount($tenant, SocialPlatform::LinkedIn);
    $healthy = engagementAccount($tenant, SocialPlatform::YouTube);

    $healthyVariant = publishedVariant($tenant, $healthy, 'post-yt');

    FakeEngagementProvider::commentsFor($broken, new ProviderApiException('linkedin', 503, null));
    FakeEngagementProvider::commentsFor($healthy, [providerComment($healthyVariant, 'yt-1')]);

    $brokenResult = commentSync()->syncAccount($broken);
    $healthyResult = commentSync()->syncAccount($healthy);

    expect($brokenResult->failureCode)->toBe('provider_api_error')
        ->and($healthyResult->created)->toBe(1)
        ->and(Comment::query()->count())->toBe(1);
});

it('marks a revoked token during a sync and notifies the workspace exactly once', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    FakeEngagementProvider::commentsFor($account, new TokenRevokedException('facebook'));

    runCommentSync((int) $account->getKey());

    expect($account->refresh()->status)->toBe(SocialAccountStatus::Revoked)
        ->and(SocialHubNotification::query()->count())->toBe(1);

    // A second run must not raise a second notification or retry the token.
    runCommentSync((int) $account->getKey());

    expect(SocialHubNotification::query()->count())->toBe(1);
});

it('does not churn the account or spam the workspace when a provider keeps refusing', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    FakeEngagementProvider::commentsFor($account, new UnsupportedCapabilityException('comments', 'facebook'));

    runCommentSync((int) $account->getKey());
    runCommentSync((int) $account->getKey());
    runCommentSync((int) $account->getKey());

    // A capability refusal is a stable fact, not an incident: nothing is
    // written, the account is not flipped to an error state, and the workspace
    // is not told three times about a condition it cannot act on.
    expect(Comment::query()->count())->toBe(0)
        ->and(SocialHubNotification::query()->count())->toBe(0)
        ->and($account->refresh()->status)->toBe(SocialAccountStatus::Connected)
        ->and(commentSync()->syncAccount($account)->failureCode)->toBe('unsupported_capability');
});

it('raises one notification per new comment, not one per sync pass', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    FakeEngagementProvider::commentsFor($account, [providerComment($variant, 'c1', 'Only comment')]);

    commentSync()->syncAccount($account);
    commentSync()->syncAccount($account);
    commentSync()->syncAccount($account);

    expect(Comment::query()->count())->toBe(1)
        ->and(SocialHubNotification::query()->count())->toBe(1)
        ->and(SocialHubNotification::query()->first()->type)->toBe('comment.new');
});

it('never writes a token into a comment notification', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $variant = publishedVariant($tenant, $account, 'post-abc');

    FakeEngagementProvider::commentsFor($account, [providerComment($variant, 'c1', 'Hello there')]);

    commentSync()->syncAccount($account);

    $notification = SocialHubNotification::query()->first();

    $encoded = json_encode($notification->toArray());

    expect($encoded)->not->toContain('access_token')
        ->not->toContain('refresh_token')
        ->not->toContain($account->token()->first()?->access_token ?? '__none__');
});
