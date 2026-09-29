<?php

declare(strict_types=1);

use App\Enums\CommentSyncStatus;
use App\Enums\SocialPlatform;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Services\Engagement\CommentFilters;
use App\Services\Engagement\CommentQueryService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/engagement_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::forget();
    useFakeEngagementProviders();
});

afterEach(fn () => TenantContext::forget());

function inbox(): CommentQueryService
{
    return app(CommentQueryService::class);
}

function filterSet(array $overrides = []): CommentFilters
{
    return CommentFilters::make($overrides);
}

function commentWith(Tenant $tenant, SocialAccount $account, array $attributes = []): Comment
{
    $variant = publishedVariant($tenant, $account);

    return Comment::factory()->create(array_merge([
        'tenant_id' => $tenant->getKey(),
        'post_variant_id' => $variant->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => $account->provider->value,
    ], $attributes));
}

it('lists comments newest first across every platform', function (): void {
    $tenant = engagementTenant();

    $facebook = engagementAccount($tenant, SocialPlatform::Facebook);
    $instagram = engagementAccount($tenant, SocialPlatform::Instagram);

    $older = commentWith($tenant, $facebook, ['content' => 'Older', 'provider_created_at' => now()->subDays(2)]);
    $newer = commentWith($tenant, $instagram, ['content' => 'Newer', 'provider_created_at' => now()]);

    $results = inbox()->paginate(filterSet(), 10);

    expect($results->total())->toBe(2)
        ->and($results->first()->getKey())->toBe($newer->getKey())
        ->and($results->last()->getKey())->toBe($older->getKey());
});

it('filters by platform', function (): void {
    $tenant = engagementTenant();

    $facebook = engagementAccount($tenant, SocialPlatform::Facebook);
    $instagram = engagementAccount($tenant, SocialPlatform::Instagram);

    commentWith($tenant, $facebook);
    commentWith($tenant, $instagram);
    commentWith($tenant, $instagram);

    expect(inbox()->paginate(filterSet(['platform' => 'instagram']), 10)->total())->toBe(2)
        ->and(inbox()->paginate(filterSet(['platform' => ['facebook', 'instagram']]), 10)->total())->toBe(3)
        ->and(inbox()->paginate(filterSet(['platform' => 'tiktok']), 10)->total())->toBe(0);
});

it('filters by account and by post', function (): void {
    $tenant = engagementTenant();

    $first = engagementAccount($tenant, SocialPlatform::Facebook);
    $second = engagementAccount($tenant, SocialPlatform::Facebook);

    $a = commentWith($tenant, $first);
    $b = commentWith($tenant, $second);

    expect(inbox()->paginate(filterSet(['account_id' => $first->getKey()]), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['account_id' => [$first->getKey(), $second->getKey()]]), 10)->total())->toBe(2)
        ->and(inbox()->paginate(filterSet(['post_variant_id' => $a->post_variant_id]), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['post_variant_id' => $b->post_variant_id]), 10)->total())->toBe(1);
});

it('searches comment text and author', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    commentWith($tenant, $account, ['content' => 'Love the new pricing page', 'author_username' => 'dana']);
    commentWith($tenant, $account, ['content' => 'Where is the docs link', 'author_username' => 'sam']);

    expect(inbox()->paginate(filterSet(['search' => 'pricing']), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['search' => 'dana']), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['search' => 'nothing here']), 10)->total())->toBe(0);
});

it('filters by date range', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    commentWith($tenant, $account, ['provider_created_at' => now()->subDays(10)]);
    commentWith($tenant, $account, ['provider_created_at' => now()->subHour()]);

    expect(inbox()->paginate(filterSet(['from' => now()->subDay()->toDateTimeString()]), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['to' => now()->subDay()->toDateTimeString()]), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet([
            'from' => now()->subDays(30)->toDateTimeString(),
            'to' => now()->toDateTimeString(),
        ]), 10)->total())->toBe(2);
});

it('rejects a date range that runs backwards', function (): void {
    expect(fn () => filterSet([
        'from' => now()->toDateTimeString(),
        'to' => now()->subWeek()->toDateTimeString(),
    ]))->toThrow(InvalidArgumentException::class);
});

it('hides deleted and hidden comments unless asked', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    commentWith($tenant, $account);
    commentWith($tenant, $account, ['is_deleted' => true]);
    commentWith($tenant, $account, ['is_hidden' => true]);

    expect(inbox()->paginate(filterSet(), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['include_deleted' => true]), 10)->total())->toBe(2)
        ->and(inbox()->paginate(filterSet(['include_hidden' => true]), 10)->total())->toBe(2)
        ->and(inbox()->paginate(filterSet([
            'include_deleted' => true,
            'include_hidden' => true,
        ]), 10)->total())->toBe(3);
});

it('filters by sync status', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    commentWith($tenant, $account);
    commentWith($tenant, $account, ['is_deleted' => true]);
    commentWith($tenant, $account, ['is_hidden' => true]);

    expect(inbox()->paginate(filterSet(['status' => 'deleted']), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['status' => 'failed']), 10)->total())->toBe(1)
        ->and(inbox()->paginate(filterSet(['status' => 'synced', 'include_deleted' => true, 'include_hidden' => true]), 10)->total())->toBe(1);
});

it('treats a comment as handled only once a reply was actually delivered', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    $unanswered = commentWith($tenant, $account);
    $pending = commentWith($tenant, $account);
    $failed = commentWith($tenant, $account);
    $answered = commentWith($tenant, $account);

    CommentReply::factory()->create([
        'comment_id' => $pending->getKey(),
        'status' => CommentSyncStatus::Pending->value,
    ]);

    CommentReply::factory()->create([
        'comment_id' => $failed->getKey(),
        'status' => CommentSyncStatus::Failed->value,
    ]);

    CommentReply::factory()->create([
        'comment_id' => $answered->getKey(),
        'status' => CommentSyncStatus::Sent->value,
    ]);

    $handled = inbox()->paginate(filterSet(['handled' => true]), 10);
    $unhandled = inbox()->paginate(filterSet(['handled' => false]), 10);

    expect($handled->total())->toBe(1)
        ->and($handled->first()->getKey())->toBe($answered->getKey())
        // A pending or failed reply leaves the comment owed an answer.
        ->and($unhandled->total())->toBe(3);
});

it('sorts by newest, oldest or most liked', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    $quiet = commentWith($tenant, $account, ['like_count' => 1, 'provider_created_at' => now()->subDay()]);
    $loud = commentWith($tenant, $account, ['like_count' => 99, 'provider_created_at' => now()]);
    $middle = commentWith($tenant, $account, ['like_count' => 5, 'provider_created_at' => now()->subHours(5)]);

    $newest = inbox()->paginate(filterSet(['sort' => 'newest']), 10)->items();
    $oldest = inbox()->paginate(filterSet(['sort' => 'oldest']), 10)->items();
    $liked = inbox()->paginate(filterSet(['sort' => 'most_liked']), 10)->items();

    expect($newest[0]->getKey())->toBe($loud->getKey())
        ->and($oldest[0]->getKey())->toBe($quiet->getKey())
        ->and($liked[0]->getKey())->toBe($loud->getKey())
        ->and($liked[2]->getKey())->toBe($quiet->getKey());
});

it('falls back to newest for an unknown sort instead of failing', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    commentWith($tenant, $account);

    expect(inbox()->paginate(filterSet(['sort' => 'by-vibes']), 10)->total())->toBe(1);
});

it('paginates the inbox', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    for ($i = 0; $i < 7; $i++) {
        commentWith($tenant, $account, ['provider_created_at' => now()->subMinutes($i)]);
    }

    $page = inbox()->paginate(filterSet(), 3);

    expect($page->total())->toBe(7)
        ->and($page->lastPage())->toBe(3)
        ->and($page->items())->toHaveCount(3);
});

// Partial platform failure

it('still returns the healthy platforms when one platform is failing', function (): void {
    $tenant = engagementTenant();

    $broken = engagementAccount($tenant, SocialPlatform::Facebook, [
        'last_error' => 'Provider API returned an unexpected error.',
    ]);
    $healthy = engagementAccount($tenant, SocialPlatform::Instagram);

    commentWith($tenant, $broken);
    commentWith($tenant, $healthy);
    commentWith($tenant, $healthy);

    $breakdown = inbox()->platformBreakdown();

    // The failing platform is reported as degraded rather than dropped, and the
    // comments that were collected are still returned.
    expect($breakdown)->toHaveKeys(['facebook', 'instagram'])
        ->and($breakdown['facebook']['degraded'])->toBeTrue()
        ->and($breakdown['facebook']['available'])->toBeFalse()
        ->and($breakdown['facebook']['last_error'])->toContain('unexpected error')
        ->and($breakdown['instagram']['available'])->toBeTrue()
        ->and($breakdown['facebook']['comments'])->toBe(1)
        ->and($breakdown['instagram']['comments'])->toBe(2);
});

it('groups comments by platform without losing one to a failure', function (): void {
    $tenant = engagementTenant();

    $broken = engagementAccount($tenant, SocialPlatform::Facebook, ['last_error' => 'Sync failed.']);
    $healthy = engagementAccount($tenant, SocialPlatform::LinkedIn);

    commentWith($tenant, $broken);
    commentWith($tenant, $healthy);
    commentWith($tenant, $healthy);

    $groups = inbox()->groupedByPlatform(filterSet(), 10);

    expect(array_keys($groups))->toBe(['facebook', 'linkedin'])
        ->and($groups['facebook']['total'])->toBe(1)
        ->and($groups['linkedin']['total'])->toBe(2)
        ->and($groups['linkedin']['comments'][0]['content'])->not->toBeNull();
});

it('counts unreplied comments across the inbox', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);

    $a = commentWith($tenant, $account);
    commentWith($tenant, $account);
    commentWith($tenant, $account);

    CommentReply::factory()->create([
        'comment_id' => $a->getKey(),
        'status' => CommentSyncStatus::Sent->value,
    ]);

    $counters = inbox()->counters();

    expect($counters['total'])->toBe(3)
        ->and($counters['unreplied'])->toBe(2)
        ->and($counters['platforms'])->toBe(1);
});

it('presents a comment without exposing a credential', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentWith($tenant, $account, ['content' => 'Nice work']);

    $presented = inbox()->present($comment);

    expect($presented['content'])->toBe('Nice work')
        ->and($presented['platform_label'])->toBe('Facebook')
        ->and($presented['author']['display_name'])->toBe($comment->authorDisplayLabel())
        ->and($presented['post'])->toHaveKeys(['post_variant_id', 'post_id', 'title', 'url']);

    expect(json_encode($presented))->not->toContain('token');
});

it('summarises reply state for a thread', function (): void {
    $tenant = engagementTenant();
    $account = engagementAccount($tenant, SocialPlatform::Facebook);
    $comment = commentWith($tenant, $account);

    CommentReply::factory()->create(['comment_id' => $comment->getKey(), 'status' => CommentSyncStatus::Sent->value]);
    CommentReply::factory()->create(['comment_id' => $comment->getKey(), 'status' => CommentSyncStatus::Failed->value]);

    $state = inbox()->replyState($comment);

    expect($state['count'])->toBe(2)
        ->and($state['sent'])->toBe(1)
        ->and($state['failed'])->toBe(1)
        ->and($state['pending'])->toBe(0);
});

// 14. Tenant isolation

it('never shows one tenant another tenants comments', function (): void {
    [$tenantA] = [engagementTenant()];

    $accountA = engagementAccount($tenantA, SocialPlatform::Facebook);
    commentWith($tenantA, $accountA, ['content' => 'Tenant A private comment']);

    TenantContext::forget();
    $tenantB = engagementTenant();
    $accountB = engagementAccount($tenantB, SocialPlatform::Facebook);
    commentWith($tenantB, $accountB, ['content' => 'Tenant B comment']);

    $results = inbox()->paginate(filterSet(), 50);

    expect($results->total())->toBe(1)
        ->and($results->first()->content)->toBe('Tenant B comment')
        ->and(inbox()->search(filterSet(['search' => 'private']), 50)->total())->toBe(0);
});

it('never groups another tenants comments into the breakdown', function (): void {
    [$tenantA] = [engagementTenant()];

    $accountA = engagementAccount($tenantA, SocialPlatform::Facebook);
    commentWith($tenantA, $accountA);

    TenantContext::forget();
    $tenantB = engagementTenant();
    $accountB = engagementAccount($tenantB, SocialPlatform::Facebook);
    commentWith($tenantB, $accountB);

    $breakdown = inbox()->platformBreakdown();

    expect($breakdown)->toHaveKey('facebook')
        ->and($breakdown['facebook']['comments'])->toBe(1)
        ->and($breakdown['facebook']['accounts'])->toBe(1)
        ->and($breakdown['facebook']['connected_accounts'])->toBe(1);

    expect(inbox()->counters()['total'])->toBe(1);
});

it('cannot read a comment row from another tenant by id', function (): void {
    [$tenantA] = [engagementTenant()];

    $theirs = commentWith($tenantA, engagementAccount($tenantA, SocialPlatform::Facebook));

    TenantContext::forget();
    engagementTenant();

    expect(Comment::query()->find($theirs->getKey()))->toBeNull()
        ->and(inbox()->paginate(filterSet(['search' => $theirs->content]), 10)->total())->toBe(0);
});
