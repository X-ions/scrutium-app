<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\PublishingAttemptStatus;
use App\Enums\ScheduledPostStatus;
use App\Enums\SocialPlatform;
use App\Jobs\Publishing\PublishPostJob;
use App\Models\PublishingAttempt;
use App\Models\ScheduledPost;
use App\Models\SocialHubNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fakes\RecordingQueueJob;

require_once __DIR__.'/../../Support/publishing_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    configureFacebookCredentials();
    registerFakeNetworks();

    $this->tenant = publishingTenant();
    $this->post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);
    $this->facebook = variantOf($this->post, SocialPlatform::Facebook);
    $this->instagram = variantOf($this->post, SocialPlatform::Instagram);
});

it('releases the job for exactly the delay the provider asked for on a 429', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(
            ['error' => ['code' => 4, 'message' => 'Application request limit reached']],
            429,
            ['Retry-After' => '120'],
        ),
    ]);

    $queueJob = runJob(queuedJob($this->facebook));

    expect($queueJob->released)->toBe([120]);

    $variant = $this->facebook->refresh();

    expect($variant->statusEnum())->toBe(PostVariantStatus::Publishing)
        ->and($variant->retry_count)->toBe(0)
        ->and(PublishingAttempt::withoutGlobalScopes()->where('status', PublishingAttemptStatus::Failed->value)->count())->toBe(0)
        ->and(PublishingAttempt::withoutGlobalScopes()->where('status', PublishingAttemptStatus::RateLimited->value)->count())->toBe(1)
        ->and(SocialHubNotification::withoutGlobalScopes()->where('type', 'post.failed')->count())->toBe(0);
});

it('records when the rate limit clears so the window is auditable', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['error' => ['code' => 4]], 429, ['Retry-After' => '90']),
    ]);

    runJob(queuedJob($this->facebook));

    $attempt = PublishingAttempt::withoutGlobalScopes()->firstOrFail();

    expect($attempt->rate_limit_reset_at)->not->toBeNull()
        ->and($attempt->rate_limit_reset_at->isFuture())->toBeTrue()
        ->and($attempt->error_code)->toBe('rate_limited');
});

it('leaves the sibling network untouched when one is rate limited', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['error' => ['code' => 4]], 429, ['Retry-After' => '60']),
        'graph.instagram.test/*' => Http::response(['id' => 'ig-ok'], 200),
    ]);

    runJob(queuedJob($this->facebook));
    runJob(queuedJob($this->instagram));

    expect($this->facebook->refresh()->statusEnum())->toBe(PostVariantStatus::Publishing)
        ->and($this->instagram->refresh()->statusEnum())->toBe(PostVariantStatus::Published);
});

it('never calls the provider for a variant that is already published', function () {
    Http::fake();

    $this->facebook->forceFill([
        'status' => PostVariantStatus::Published->value,
        'provider_post_id' => 'fb-already-live',
        'published_at' => now(),
    ])->save();

    runJob(queuedJob($this->facebook));

    Http::assertNothingSent();

    expect($this->facebook->refresh()->provider_post_id)->toBe('fb-already-live');
});

it('never calls the provider for a cancelled variant', function () {
    Http::fake();

    $this->facebook->forceFill([
        'status' => PostVariantStatus::Cancelled->value,
    ])->save();

    $job = queuedJob($this->facebook);
    $queueJob = runJob($job);

    Http::assertNothingSent();

    expect($this->facebook->refresh()->statusEnum())->toBe(PostVariantStatus::Cancelled)
        ->and(PublishingAttempt::withoutGlobalScopes()->count())->toBe(0)
        ->and(ScheduledPost::withoutGlobalScopes()->firstOrFail()->statusEnum())->toBe(ScheduledPostStatus::Cancelled);
});

it('is a no-op when the variant was deleted while it sat in the queue', function () {
    Http::fake();

    $job = queuedJob($this->facebook);
    $this->facebook->forceDelete();

    runJob($job);

    Http::assertNothingSent();

    expect(PublishingAttempt::withoutGlobalScopes()->count())->toBe(0);
});

it('retries a provider outage and lands in failed with a notification once the budget is spent', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['error' => ['code' => 2]], 500),
    ]);

    $queueJob = runJob(queuedJob($this->facebook), new RecordingQueueJob(1));

    $variant = $this->facebook->refresh();

    expect($variant->statusEnum())->toBe(PostVariantStatus::Publishing)
        ->and($queueJob->released)->toBe([30])
        ->and(PublishingAttempt::withoutGlobalScopes()->where('status', PublishingAttemptStatus::Failed->value)->count())->toBe(1)
        ->and(SocialHubNotification::withoutGlobalScopes()->where('type', 'post.failed')->count())->toBe(0);

    publishUntilTerminal($variant);

    $variant = $variant->refresh();

    expect($variant->statusEnum())->toBe(PostVariantStatus::Failed)
        ->and($variant->error_message)->toContain('Facebook')
        ->and($variant->retry_count)->toBe((int) config('socialhub.publishing.max_attempts'));

    expect(PublishingAttempt::withoutGlobalScopes()
        ->whereIn('status', [PublishingAttemptStatus::Failed->value, PublishingAttemptStatus::Success->value])
        ->count())->toBe((int) config('socialhub.publishing.max_attempts'));

    expect(SocialHubNotification::withoutGlobalScopes()->where('type', 'post.failed')->count())->toBe(1);

    $scheduled = ScheduledPost::withoutGlobalScopes()->where('post_variant_id', $variant->getKey())->firstOrFail();

    expect($scheduled->statusEnum())->toBe(ScheduledPostStatus::Failed)
        ->and($scheduled->last_error)->not->toBeNull();
});

it('backs off further on each attempt instead of hammering the provider', function () {
    Http::fake(['graph.facebook.test/*' => Http::response(['error' => ['code' => 2]], 500)]);

    $delays = [];
    $variant = $this->facebook;

    for ($run = 0; $run < 4; $run++) {
        $variant->refresh();
        $queueJob = runJob(queuedJob($variant), new RecordingQueueJob($run + 1));
        $delays[] = $queueJob->released[0] ?? null;
    }

    expect($delays)->toBe(config('socialhub.publishing.backoff'));
});

it('fails a 4xx immediately because retrying the same payload cannot help', function () {
    Http::fake([
        'graph.facebook.test/*' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter']], 400),
    ]);

    $queueJob = runJob(queuedJob($this->facebook));

    expect($this->facebook->refresh()->statusEnum())->toBe(PostVariantStatus::Failed)
        ->and($queueJob->released)->toBe([])
        ->and(SocialHubNotification::withoutGlobalScopes()->where('type', 'post.failed')->count())->toBe(1);
});

it('fails fast when the account needs reconnecting and says so', function () {
    Http::fake();

    $this->facebook->socialAccount->forceFill(['status' => 'expired'])->save();

    runJob(queuedJob($this->facebook));

    Http::assertNothingSent();

    expect($this->facebook->refresh()->statusEnum())->toBe(PostVariantStatus::Failed)
        ->and($this->facebook->refresh()->error_message)->toContain('reconnected');
});

it('writes a sanitised request payload that carries no credential material', function () {
    Http::fake(['graph.facebook.test/*' => Http::response(['id' => 'fb-77'], 200)]);

    runJob(queuedJob($this->facebook));

    $attempt = PublishingAttempt::withoutGlobalScopes()->firstOrFail();

    expect($attempt->request_payload)->toHaveKey('caption')
        ->and(json_encode($attempt->toArray()))->not->toContain('page-access-token')
        ->and($attempt->statusEnum())->toBe(PublishingAttemptStatus::Success);
});

it('marks the schedule and the post published only after the provider id lands', function () {
    Http::fake(['graph.facebook.test/*' => Http::response(['id' => 'fb-5150'], 200)]);

    runJob(queuedJob($this->facebook));

    $scheduled = ScheduledPost::withoutGlobalScopes()
        ->where('post_variant_id', $this->facebook->getKey())
        ->firstOrFail();

    expect($scheduled->statusEnum())->toBe(ScheduledPostStatus::Published)
        ->and($scheduled->processed_at)->not->toBeNull()
        ->and($this->facebook->refresh()->provider_post_id)->toBe('fb-5150')
        ->and($this->post->refresh()->statusEnum())->toBe(PostStatus::Publishing);
});

it('overlaps on the variant id so a worker restart cannot double publish', function () {
    $middleware = (new PublishPostJob(
        postVariantId: $this->facebook->getKey(),
        scheduledPostId: 1,
        jobId: 'job_test',
    ))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(Illuminate\Queue\Middleware\WithoutOverlapping::class);
});

it('reads its retry budget and backoff from configuration', function () {
    config()->set('socialhub.publishing.max_attempts', 7);
    config()->set('socialhub.publishing.backoff', [5, 15, 45]);

    $job = new PublishPostJob(
        postVariantId: $this->facebook->getKey(),
        scheduledPostId: 1,
        jobId: 'job_test',
    );

    expect($job->tries)->toBe(7)
        ->and($job->backoff())->toBe([5, 15, 45])
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});

it('sends the job to the socialhub queue when one is configured', function () {
    config()->set('socialhub.queue', 'socialhub');

    $job = new PublishPostJob(
        postVariantId: $this->facebook->getKey(),
        scheduledPostId: 1,
        jobId: 'job_test',
    );

    expect($job->queue)->toBe('socialhub');
});
