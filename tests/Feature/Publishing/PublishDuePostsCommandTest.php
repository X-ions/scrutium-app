<?php

declare(strict_types=1);

use App\Enums\PostVariantStatus;
use App\Enums\PublishingAttemptStatus;
use App\Enums\ScheduledPostStatus;
use App\Enums\SocialPlatform;
use App\Jobs\Publishing\PublishPostJob;
use App\Models\PublishingAttempt;
use App\Models\ScheduledPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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

/**
 * `updated_at` is not mass assignable on this model, so a row that must look
 * abandoned is back-dated with an explicit write.
 */
function scheduleRow(\App\Models\PostVariant $variant, array $attributes = []): ScheduledPost
{
    $scheduled = ScheduledPost::withoutGlobalScopes()->create($attributes + [
        'post_variant_id' => $variant->getKey(),
        'scheduled_at' => now()->subHour(),
        'timezone' => 'UTC',
        'status' => ScheduledPostStatus::Pending->value,
    ]);

    if (array_key_exists('updated_at', $attributes)) {
        $scheduled->forceFill(['updated_at' => $attributes['updated_at']])->saveQuietly();
    }

    return $scheduled;
}

it('dispatches nothing before a scheduled post is due', function () {
    Queue::fake();

    scheduleRow($this->facebook, ['scheduled_at' => now()->addMinutes(5)]);

    $this->artisan('publish:due')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('claims a due row and dispatches one job for it', function () {
    Queue::fake();

    $scheduled = scheduleRow($this->facebook);

    $this->artisan('publish:due')->assertSuccessful();

    Queue::assertPushed(PublishPostJob::class, 1);

    $claimed = ScheduledPost::withoutGlobalScopes()->find($scheduled->getKey());

    expect($claimed->statusEnum())->toBe(ScheduledPostStatus::Queued)
        ->and($claimed->job_id)->toStartWith('job_');
});

it('claims each due row exactly once across two ticks', function () {
    Queue::fake();

    scheduleRow($this->facebook);

    $this->artisan('publish:due')->assertSuccessful();
    $this->artisan('publish:due')->assertSuccessful();

    Queue::assertPushed(PublishPostJob::class, 1);
});

it('re-queues a row a dead worker left mid-flight', function () {
    Queue::fake();

    $scheduled = scheduleRow($this->facebook, [
        'status' => ScheduledPostStatus::Processing->value,
        'job_id' => 'job_deadworker',
        'updated_at' => now()->subMinutes(45),
    ]);

    $this->facebook->forceFill([
        'status' => PostVariantStatus::Publishing->value,
    ])->save();

    $this->artisan('publish:due --stale-minutes=10')->assertSuccessful();

    Queue::assertPushed(PublishPostJob::class, 1);

    $recovered = ScheduledPost::withoutGlobalScopes()->find($scheduled->getKey());

    expect($recovered->statusEnum())->toBe(ScheduledPostStatus::Queued)
        ->and($recovered->job_id)->not->toBe('job_deadworker')
        ->and($this->facebook->refresh()->statusEnum())->toBe(PostVariantStatus::Scheduled);
});

it('leaves a freshly claimed row alone until it goes stale', function () {
    Queue::fake();

    $scheduled = scheduleRow($this->facebook, [
        'status' => ScheduledPostStatus::Processing->value,
    ]);

    $this->artisan('publish:due --stale-minutes=10')->assertSuccessful();

    Queue::assertNothingPushed();

    expect(ScheduledPost::withoutGlobalScopes()->find($scheduled->getKey())->statusEnum())
        ->toBe(ScheduledPostStatus::Processing);
});

it('closes out a stalled row whose variant actually went live before the worker died', function () {
    Queue::fake();

    $scheduled = scheduleRow($this->facebook, [
        'status' => ScheduledPostStatus::Processing->value,
        'updated_at' => now()->subMinutes(45),
    ]);

    $this->facebook->forceFill([
        'status' => PostVariantStatus::Published->value,
        'provider_post_id' => 'fb-landed',
        'published_at' => now(),
    ])->save();

    $this->artisan('publish:due --stale-minutes=10')->assertSuccessful();

    Queue::assertNothingPushed();

    expect(ScheduledPost::withoutGlobalScopes()->find($scheduled->getKey())->statusEnum())
        ->toBe(ScheduledPostStatus::Published);
});

it('cancels a stalled row whose variant was cancelled while queued', function () {
    Queue::fake();

    $scheduled = scheduleRow($this->facebook, [
        'status' => ScheduledPostStatus::Processing->value,
        'updated_at' => now()->subMinutes(45),
    ]);

    $this->facebook->forceFill(['status' => PostVariantStatus::Cancelled->value])->save();

    $this->artisan('publish:due --stale-minutes=10')->assertSuccessful();

    Queue::assertNothingPushed();

    expect(ScheduledPost::withoutGlobalScopes()->find($scheduled->getKey())->statusEnum())
        ->toBe(ScheduledPostStatus::Cancelled);
});

it('re-queues a single failed variant from the composer retry action', function () {
    Queue::fake();

    $variant = variantOf(postWithVariants($this->tenant, [SocialPlatform::Facebook]), SocialPlatform::Facebook);

    $variant->forceFill([
        'status' => PostVariantStatus::Failed->value,
        'error_message' => 'Facebook rejected the request. Nothing was published.',
    ])->save();

    $this->artisan('publishing:retry-variant', ['variantId' => $variant->getKey()])
        ->assertSuccessful();

    Queue::assertPushed(PublishPostJob::class, 1);

    expect(ScheduledPost::withoutGlobalScopes()->where('post_variant_id', $variant->getKey())->count())->toBe(1);
});

it('fails the retry fast when the network still cannot take the content', function () {
    Queue::fake();

    $post = postWithVariants($this->tenant, [SocialPlatform::Instagram], [
        'status' => PostVariantStatus::Failed->value,
        'media_ids' => [],
    ]);

    $variant = variantOf($post, SocialPlatform::Instagram);

    $exit = Illuminate\Support\Facades\Artisan::call('publishing:retry-variant', ['variantId' => $variant->getKey()]);

    expect($exit)->toBe(1)
        ->and(Illuminate\Support\Facades\Artisan::output())->toContain('cannot be retried yet');

    Queue::assertNothingPushed();

    expect($variant->refresh()->statusEnum())->toBe(PostVariantStatus::Failed)
        ->and($variant->error_message)->toContain('Instagram');
});

it('refuses to retry a variant that never failed because it is already live', function () {
    Queue::fake();

    $this->facebook->forceFill([
        'status' => PostVariantStatus::Published->value,
        'provider_post_id' => 'fb-live',
    ])->save();

    $this->artisan('publishing:retry-variant', ['variantId' => $this->facebook->getKey()])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('rejects a retry for a variant that does not exist', function () {
    $this->artisan('publishing:retry-variant', ['variantId' => 999999])->assertFailed();
});

it('prunes old attempts but keeps the recent history of each variant', function () {
    $old = PublishingAttempt::withoutGlobalScopes()->create([
        'post_variant_id' => $this->facebook->getKey(),
        'attempt_number' => 1,
        'status' => PublishingAttemptStatus::Failed->value,
        'started_at' => now()->subDays(120),
        'completed_at' => now()->subDays(120),
    ]);

    $oldTwo = PublishingAttempt::withoutGlobalScopes()->create([
        'post_variant_id' => $this->facebook->getKey(),
        'attempt_number' => 2,
        'status' => PublishingAttemptStatus::Failed->value,
        'started_at' => now()->subDays(119),
        'completed_at' => now()->subDays(119),
    ]);

    $recent = PublishingAttempt::withoutGlobalScopes()->create([
        'post_variant_id' => $this->facebook->getKey(),
        'attempt_number' => 3,
        'status' => PublishingAttemptStatus::Failed->value,
        'started_at' => now()->subDay(),
        'completed_at' => now()->subDay(),
    ]);

    $this->artisan('publishing:prune-attempts --days=30 --keep=1')->assertSuccessful();

    expect(PublishingAttempt::withoutGlobalScopes()->find($old->getKey()))->toBeNull()
        ->and(PublishingAttempt::withoutGlobalScopes()->find($oldTwo->getKey()))->toBeNull()
        ->and(PublishingAttempt::withoutGlobalScopes()->find($recent->getKey()))->not->toBeNull();
});

it('reports what it would prune without deleting anything in a dry run', function () {
    $attempt = PublishingAttempt::withoutGlobalScopes()->create([
        'post_variant_id' => $this->facebook->getKey(),
        'attempt_number' => 1,
        'status' => PublishingAttemptStatus::Failed->value,
        'started_at' => now()->subDays(200),
        'completed_at' => now()->subDays(200),
    ]);

    $this->artisan('publishing:prune-attempts --days=30 --keep=0 --dry-run')
        ->expectsOutputToContain('would be deleted')
        ->assertSuccessful();

    expect(PublishingAttempt::withoutGlobalScopes()->find($attempt->getKey()))->not->toBeNull();
});

it('is scheduled every minute with the prune running daily', function () {
    $events = collect(Illuminate\Support\Facades\Schedule::events())
        ->mapWithKeys(fn ($event): array => [$event->expression => (string) $event->command]);

    expect($events->keys()->all())->toContain('* * * * *', '0 0 * * *')
        ->and(implode(' ', $events->values()->all()))->toContain('publish:due')
        ->and(implode(' ', $events->values()->all()))->toContain('publishing:prune-attempts');
});
