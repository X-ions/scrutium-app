<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\ScheduledPostStatus;
use App\Enums\SocialPlatform;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\ScheduledPost;
use App\Services\Publishing\EntitlementService;
use App\Services\Publishing\SchedulingLimitExceededException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/publishing_helpers.php';

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = publishingTenant(['scheduled_posts_limit' => 3]);
    $this->post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);
    $this->entitlements = app(EntitlementService::class);
});

/**
 * `post_variants` is unique per (post, account) and `scheduled_posts` per
 * variant, so each slot books its own account.
 */
function bookSlot(Post $post, ScheduledPostStatus $status = ScheduledPostStatus::Pending): PostVariant
{
    $account = connectedAccount($post->tenant_id, SocialPlatform::Facebook);

    $variant = PostVariant::factory()->create([
        'post_id' => $post->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialPlatform::Facebook->value,
        'status' => PostVariantStatus::Pending->value,
    ]);

    ScheduledPost::withoutGlobalScopes()->create([
        'post_variant_id' => $variant->getKey(),
        'scheduled_at' => now()->addHour(),
        'timezone' => 'UTC',
        'status' => $status->value,
    ]);

    return $variant;
}

it('reads the limit from the workspace row', function () {
    expect($this->entitlements->scheduledPostsLimit($this->tenant))->toBe(3);
});

it('treats a null limit as unlimited rather than as zero', function () {
    $this->tenant->forceFill(['scheduled_posts_limit' => null])->save();

    expect($this->entitlements->scheduledPostsLimit($this->tenant))->toBeNull()
        ->and($this->entitlements->remainingScheduledPosts($this->tenant))->toBeNull()
        ->and($this->entitlements->canScheduleMore($this->tenant, 500))->toBeTrue();
});

it('treats a zero limit as a real refusal', function () {
    $this->tenant->forceFill(['scheduled_posts_limit' => 0])->save();

    expect($this->entitlements->canScheduleMore($this->tenant))->toBeFalse();

    expect(fn () => $this->entitlements->assertCanScheduleMore($this->tenant))
        ->toThrow(SchedulingLimitExceededException::class);
});

it('counts only rows that still occupy a slot', function () {
    bookSlot($this->post, ScheduledPostStatus::Published);
    bookSlot($this->post, ScheduledPostStatus::Cancelled);
    bookSlot($this->post, ScheduledPostStatus::Failed);
    bookSlot($this->post, ScheduledPostStatus::Processing);

    expect($this->entitlements->scheduledPostsUsed($this->tenant))->toBe(1)
        ->and($this->entitlements->remainingScheduledPosts($this->tenant))->toBe(2);
});

it('counts pending and queued rows because both still occupy a slot', function () {
    bookSlot($this->post, ScheduledPostStatus::Pending);
    bookSlot($this->post, ScheduledPostStatus::Queued);

    expect($this->entitlements->scheduledPostsUsed($this->tenant))->toBe(2);
});

it('throws with a user-facing message that names the limit', function () {
    bookSlot($this->post);
    bookSlot($this->post);
    bookSlot($this->post);

    try {
        $this->entitlements->assertCanScheduleMore($this->tenant);
        $this->fail('Expected the limit to be enforced.');
    } catch (SchedulingLimitExceededException $e) {
        expect($e->userFacingError->code)->toBe('scheduling_limit_reached')
            ->and($e->userFacingError->userMessage)->toContain('3 scheduled posts')
            ->and($e->userFacingError->remediation)->toContain('upgrade the workspace plan')
            ->and($e->userFacingError->retryable)->toBeFalse()
            ->and($e->limit)->toBe(3)
            ->and($e->used)->toBe(3);
    }
});

it('does not count another workspace usage against this one', function () {
    $otherTenant = publishingTenant(['scheduled_posts_limit' => 3]);
    $otherPost = postWithVariants($otherTenant, [SocialPlatform::Facebook]);

    bookSlot($otherPost);
    bookSlot($otherPost);
    bookSlot($otherPost);

    expect($this->entitlements->scheduledPostsUsed($otherTenant))->toBe(3)
        ->and($this->entitlements->canScheduleMore($otherTenant))->toBeFalse()
        ->and($this->entitlements->scheduledPostsUsed($this->tenant))->toBe(0)
        ->and($this->entitlements->canScheduleMore($this->tenant))->toBeTrue();
});

it('re-reads the count after a flush so a write is never served from a stale memo', function () {
    expect($this->entitlements->scheduledPostsUsed($this->tenant))->toBe(0);

    bookSlot($this->post);
    bookSlot($this->post);

    expect($this->entitlements->scheduledPostsUsed($this->tenant))->toBe(0);

    $this->entitlements->flush();

    expect($this->entitlements->scheduledPostsUsed($this->tenant))->toBe(2);
});

it('reports the post as still a draft while no variant is scheduled yet', function () {
    expect($this->post->refresh()->statusEnum())->toBe(PostStatus::Draft);
});
