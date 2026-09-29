<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\SocialPlatform;
use App\Jobs\Publishing\PublishPostJob;
use App\Models\PostVariant;
use App\Models\ScheduledPost;
use App\Models\SocialHubNotification;
use App\Services\Publishing\PublishingService;
use App\Services\Publishing\SchedulingLimitExceededException;
use App\Services\Publishing\UnsupportedVariantException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    configureFacebookCredentials();
    registerFakeNetworks();

    $this->publishing = app(PublishingService::class);
    $this->tenant = publishingTenant();
});

it('dispatches exactly one job per variant and never publishes in the request', function () {
    Queue::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    $this->publishing->publishNow($post, authorOf($post));

    Queue::assertPushed(PublishPostJob::class, 2);
    Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->postVariantId === $post->variants[0]->getKey());
    Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->postVariantId === $post->variants[1]->getKey());

    expect(ScheduledPost::withoutGlobalScopes()->count())->toBe(2);

    Http::assertNothingSent();
});

it('gives every variant its own schedule row so one network cannot block another', function () {
    Queue::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram, SocialPlatform::Facebook]);

    $this->publishing->publishNow($post, authorOf($post));

    $scheduled = ScheduledPost::withoutGlobalScopes()->pluck('post_variant_id');

    expect($scheduled)->toHaveCount(3)
        ->and($scheduled->unique())->toHaveCount(3);
});

it('fails only the network that broke and keeps the post status honest', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['error' => ['code' => 1, 'message' => 'Server error']], 500),
        'graph.instagram.test/*' => Http::response(['id' => 'ig-7781'], 200),
    ]);

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    foreach ($post->variants as $variant) {
        runJob(queuedJob($variant));
    }

    $facebook = variantOf($post->refresh(), SocialPlatform::Facebook);
    $instagram = variantOf($post, SocialPlatform::Instagram);

    expect($facebook->statusEnum())->toBe(PostVariantStatus::Publishing)
        ->and($facebook->error_message)->toContain('Facebook');

    expect($instagram->statusEnum())->toBe(PostVariantStatus::Published)
        ->and($instagram->provider_post_id)->toBe('ig-7781')
        ->and($instagram->provider_post_url)->toContain('ig-7781')
        ->and($instagram->published_at)->not->toBeNull();

    expect($post->refresh()->statusEnum())->toBe(PostStatus::Publishing);

    publishUntilTerminal($facebook);

    expect($facebook->refresh()->statusEnum())->toBe(PostVariantStatus::Failed)
        ->and($facebook->error_message)->not->toBeEmpty()
        ->and($post->refresh()->statusEnum())->toBe(PostStatus::Failed);
});

it('marks the post published when every variant reaches its network', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['id' => 'fb-9001'], 200),
        'graph.instagram.test/*' => Http::response(['id' => 'ig-9002'], 200),
    ]);

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    foreach ($post->variants as $variant) {
        runJob(queuedJob($variant));
    }

    expect($post->refresh()->statusEnum())->toBe(PostStatus::Published)
        ->and($post->published_at)->not->toBeNull();

    expect(SocialHubNotification::withoutGlobalScopes()->where('type', 'post.published')->count())->toBe(1);
});

it('does not create a duplicate schedule when the same post is published twice', function () {
    Queue::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    $this->publishing->publishNow($post, authorOf($post));
    $this->publishing->publishNow($post, authorOf($post));
    $this->publishing->publishNow($post, authorOf($post));

    expect(ScheduledPost::withoutGlobalScopes()->count())->toBe(2);

    Queue::assertPushed(PublishPostJob::class, 6);
});

it('does not re-schedule a variant that already reached a network', function () {
    Queue::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    variantOf($post, SocialPlatform::Facebook)->forceFill([
        'status' => PostVariantStatus::Published->value,
        'provider_post_id' => 'fb-already',
        'published_at' => now(),
    ])->save();

    $this->publishing->publishNow($post, authorOf($post));

    Queue::assertPushed(PublishPostJob::class, 1);

    expect(ScheduledPost::withoutGlobalScopes()->whereIn('post_variant_id', [
        variantOf($post, SocialPlatform::Instagram)->getKey(),
    ])->count())->toBe(1);
});

it('rejects a variant whose network cannot take that content and still publishes the rest', function () {
    Queue::fake();

    Http::fake([
        'graph.facebook.test/*' => Http::response(['id' => 'fb-4411'], 200),
        'graph.instagram.test/*' => Http::response(['id' => 'ig-0000'], 200),
    ]);

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram], [
        'media_ids' => [],
    ]);

    $this->publishing->publishNow($post, authorOf($post));

    $instagram = variantOf($post->refresh(), SocialPlatform::Instagram);
    $facebook = variantOf($post, SocialPlatform::Facebook);

    expect($instagram->statusEnum())->toBe(PostVariantStatus::Failed)
        ->and($instagram->error_message)->toContain('Instagram')
        ->and($instagram->error_message)->toContain('text-only posts')
        ->and($facebook->statusEnum())->not->toBe(PostVariantStatus::Failed);

    Queue::assertPushed(PublishPostJob::class, 1);

    runJob(queuedJob($facebook->refresh()));

    expect($facebook->refresh()->statusEnum())->toBe(PostVariantStatus::Published)
        ->and($post->refresh()->statusEnum())->toBe(PostStatus::Failed);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.instagram.test'));
});

it('never reaches the provider for a variant blocked before dispatch', function () {
    Queue::fake();
    Http::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Instagram], ['media_ids' => []]);

    $this->publishing->publishNow($post, authorOf($post));

    expect(variantOf($post->refresh(), SocialPlatform::Instagram)->statusEnum())->toBe(PostVariantStatus::Failed);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('refuses to re-queue a variant whose account still cannot publish', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Instagram], [
        'status' => PostVariantStatus::Failed->value,
        'error_message' => 'Previous failure.',
        'media_ids' => [],
    ]);

    $variant = variantOf($post, SocialPlatform::Instagram);

    expect(fn () => $this->publishing->retryVariant($variant, authorOf($post)))
        ->toThrow(UnsupportedVariantException::class);

    expect($variant->refresh()->statusEnum())->toBe(PostVariantStatus::Failed);
});

it('re-queues a failed variant that is now publishable', function () {
    Queue::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Instagram], [
        'status' => PostVariantStatus::Failed->value,
        'media_ids' => ['media-1'],
    ]);

    $variant = variantOf($post, SocialPlatform::Instagram);

    $scheduled = $this->publishing->retryVariant($variant, authorOf($post));

    expect($scheduled->statusEnum()->value)->toBe('queued')
        ->and($scheduled->statusEnum()->isTerminal())->toBeFalse();

    Queue::assertPushed(PublishPostJob::class, 1);
});

it('refuses to schedule beyond the workspace entitlement', function () {
    Queue::fake();

    $this->tenant->forceFill(['scheduled_posts_limit' => 1])->save();

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    expect(fn () => $this->publishing->publishNow($post, authorOf($post)))
        ->toThrow(SchedulingLimitExceededException::class);

    Queue::assertNothingPushed();
    expect(ScheduledPost::withoutGlobalScopes()->count())->toBe(0);
});

it('parks every variant in the future rather than publishing on the request', function () {
    Queue::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    $this->publishing->schedule($post, now()->addHours(3), 'Europe/Lisbon', authorOf($post));

    Queue::assertNothingPushed();

    $scheduled = ScheduledPost::withoutGlobalScopes()->get();

    expect($scheduled)->toHaveCount(2)
        ->and($scheduled->pluck('timezone')->unique()->all())->toBe(['Europe/Lisbon'])
        ->and($scheduled->map(fn (ScheduledPost $row): string => $row->statusEnum()->value)->unique()->values()->all())->toBe(['pending']);

    expect($scheduled->every(fn (ScheduledPost $row): bool => $row->scheduled_at->isFuture()))->toBeTrue();
    expect($post->refresh()->statusEnum())->toBe(PostStatus::Scheduled);
});

it('never writes a token into a stored attempt payload', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response([
            'id' => 'fb-9911',
            'access_token' => 'super-secret-page-token',
        ], 200),
    ]);

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);

    runJob(queuedJob(variantOf($post, SocialPlatform::Facebook)));

    $payload = (string) json_encode(
        \App\Models\PublishingAttempt::withoutGlobalScopes()->get()->map->toArray()->all()
    );

    expect($payload)->not->toContain('page-access-token')
        ->and($payload)->not->toContain('super-secret-page-token');
});

it('raises a high priority tenant notification once a variant is dead lettered', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['error' => ['code' => 1]], 500),
    ]);

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);
    $variant = variantOf($post, SocialPlatform::Facebook);

    publishUntilTerminal($variant);

    $notification = SocialHubNotification::withoutGlobalScopes()
        ->where('type', 'post.failed')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->priority)->toBe('high')
        ->and($notification->tenant_id)->toBe($this->tenant->getKey())
        ->and($notification->data['post_variant_id'])->toBe($variant->getKey());
});

it('does not notify the tenant while a variant is still retrying', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['error' => ['code' => 1]], 500),
    ]);

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);

    runJob(queuedJob(variantOf($post, SocialPlatform::Facebook)));

    expect(SocialHubNotification::withoutGlobalScopes()->where('type', 'post.failed')->count())->toBe(0);
});

it('keeps a cancelled variant out of the publishing path entirely', function () {
    Http::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);

    variantOf($post, SocialPlatform::Facebook)->forceFill([
        'status' => PostVariantStatus::Cancelled->value,
    ])->save();

    $this->publishing->publishNow($post, authorOf($post));

    expect(ScheduledPost::withoutGlobalScopes()->count())->toBe(0)
        ->and(variantOf($post, SocialPlatform::Facebook)->statusEnum())->toBe(PostVariantStatus::Cancelled);
});

it('tracks the variant in the parent post relationship', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);

    $variant = PostVariant::withoutGlobalScopes()->findOrFail($post->variants[0]->getKey());

    expect($variant->post->is($post))->toBeTrue();
});
