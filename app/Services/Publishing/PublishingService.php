<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\ScheduledPostStatus;
use App\Events\Publishing\PostFailed;
use App\Jobs\Publishing\PublishPostJob;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\ScheduledPost;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\SocialProviderRegistry;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a post into one queued job per network.
 *
 * The unit of publishing is the variant, never the post. Facebook refusing a
 * caption is Instagram's problem only in the sense that it must not be: the
 * loop below has no shared failure path, no enclosing transaction that could
 * roll back a sibling, and no short-circuit on the first error. Each variant
 * gets its own ScheduledPost row, its own job, and its own outcome.
 */
class PublishingService
{
    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly VariantCapabilityGate $gate,
        private readonly PostStatusResolver $statuses,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Publish every variant as soon as a worker picks it up.
     */
    public function publishNow(Post $post, User $actor): void
    {
        $this->dispatchVariants($post, now(), 'UTC', $actor, immediate: true);
    }

    /**
     * Park every variant for later. The queue still carries the work — nothing
     * is ever published from inside the HTTP request.
     */
    public function schedule(Post $post, CarbonInterface $at, string $timezone, User $actor): void
    {
        $this->dispatchVariants($post, $at, $timezone, $actor, immediate: false);
    }

    /**
     * Re-queue a single variant, used by the composer's Retry action. The
     * capability gate runs again here because the reason a variant failed ten
     * minutes ago may have been fixed — or not.
     */
    public function retryVariant(PostVariant $variant, User $actor): ScheduledPost
    {
        $post = $variant->post;

        if ($post === null) {
            throw new \RuntimeException(sprintf('Post variant %d has no parent post.', $variant->getKey()));
        }

        $error = $this->capabilityError($variant);

        if ($error !== null) {
            $this->failVariant($variant, $error, StructuredLog::jobId(), $variant->retry_count);

            throw new UnsupportedVariantException($error);
        }

        return $this->queueVariant($post, $variant, now(), 'UTC', $actor, immediate: true, force: true);
    }

    /**
     * @return list<ScheduledPost>
     */
    public function dispatchVariants(
        Post $post,
        DateTimeInterface $at,
        string $timezone,
        User $actor,
        bool $immediate,
    ): array {
        $post->loadMissing('variants.socialAccount');

        $variants = $post->getRelation('variants')
            ->filter(fn (PostVariant $variant): bool => ! in_array(
                $variant->statusEnum(),
                [PostVariantStatus::Published, PostVariantStatus::Cancelled],
                true,
            ))
            ->values();

        $tenant = $this->tenantFor($post);

        if ($tenant !== null) {
            $newSlots = $variants->reject(
                fn (PostVariant $variant): bool => $variant->scheduledPost()->exists(),
            )->count();

            if ($newSlots > 0) {
                $this->entitlements->assertCanScheduleMore($tenant, $newSlots);
            }
        }

        $queued = [];
        $skipped = 0;

        foreach ($variants as $variant) {
            $error = $this->capabilityError($variant);

            if ($error !== null) {
                $this->failVariant($variant, $error, StructuredLog::jobId(), 0);

                $skipped++;

                continue;
            }

            $queued[] = $this->queueVariant($post, $variant, $at, $timezone, $actor, $immediate);
        }

        $this->statuses->sync($post->refresh());
        $this->entitlements->flush();

        StructuredLog::write('publishing.dispatched', [
            'organization_id' => $post->tenant_id,
            'post_id' => $post->getKey(),
            'variant_id' => null,
            'provider' => null,
            'status' => $post->status instanceof PostStatus ? $post->status->value : null,
            'attempt' => 0,
            'error_code' => null,
            'retry_in_seconds' => null,
            'queued' => count($queued),
            'rejected' => $skipped,
            'immediate' => $immediate,
            'scheduled_at' => $at->format(DateTimeInterface::ATOM),
            'timezone' => $timezone,
            'actor_id' => $actor->getKey(),
        ]);

        return $queued;
    }

    /**
     * Create-or-reuse the ScheduledPost for a variant and dispatch its job.
     *
     * The unique index on `post_variant_id` is the real guarantee: the row lock
     * makes the read-then-write atomic within a process, and the index turns a
     * lost race between two workers into a caught exception rather than a
     * duplicate publish.
     */
    public function queueVariant(
        Post $post,
        PostVariant $variant,
        DateTimeInterface $at,
        string $timezone,
        User $actor,
        bool $immediate,
        bool $force = false,
    ): ScheduledPost {
        $scheduled = $this->resolveScheduledPost($variant, $at, $timezone, $immediate, $force);

        if ($scheduled->statusEnum() === ScheduledPostStatus::Published) {
            return $scheduled;
        }

        if ($immediate) {
            $this->pushJob($scheduled);
        }

        return $scheduled;
    }

    private function resolveScheduledPost(
        PostVariant $variant,
        DateTimeInterface $at,
        string $timezone,
        bool $immediate,
        bool $force,
    ): ScheduledPost {
        try {
            return DB::transaction(function () use ($variant, $at, $timezone, $immediate, $force): ScheduledPost {
                $existing = ScheduledPost::query()
                    ->where('post_variant_id', $variant->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $this->refreshSchedule($existing, $variant, $at, $timezone, $immediate);

                    if ($force) {
                        $existing->forceFill([
                            'status' => ScheduledPostStatus::Pending->value,
                            'last_error' => null,
                            'processed_at' => null,
                        ])->save();
                    }

                    return $existing;
                }

                $created = ScheduledPost::create([
                    'post_variant_id' => $variant->getKey(),
                    'scheduled_at' => $immediate ? now() : $at,
                    'timezone' => $timezone,
                    'status' => ScheduledPostStatus::Pending->value,
                    'job_id' => StructuredLog::jobId(),
                    'attempts' => 0,
                    'max_attempts' => (int) config('socialhub.publishing.max_attempts', 5),
                ]);

                $this->refreshSchedule($created, $variant, $at, $timezone, $immediate);

                return $created;
            });
        } catch (QueryException $e) {
            $existing = ScheduledPost::query()
                ->where('post_variant_id', $variant->getKey())
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Re-pointing an existing row must never resurrect a finished publish, so
     * only a row that has not been published is reset to the new time.
     */
    private function refreshSchedule(
        ScheduledPost $scheduled,
        PostVariant $variant,
        DateTimeInterface $at,
        string $timezone,
        bool $immediate,
    ): void {
        $scheduled->timezone = $timezone;

        if ($immediate) {
            $scheduled->scheduled_at = now();
        } elseif ($scheduled->statusEnum() !== ScheduledPostStatus::Published) {
            $scheduled->scheduled_at = $at;
        }

        if ($scheduled->isDirty()) {
            $scheduled->save();
        }

        $variant->forceFill([
            'scheduled_at' => $scheduled->scheduled_at,
            'status' => $immediate
                ? PostVariantStatus::Pending->value
                : PostVariantStatus::Scheduled->value,
            'error_message' => null,
        ])->save();
    }

    private function pushJob(ScheduledPost $scheduled): void
    {
        $job = new PublishPostJob(
            postVariantId: (int) $scheduled->post_variant_id,
            scheduledPostId: (int) $scheduled->getKey(),
            jobId: (string) ($scheduled->job_id ?: StructuredLog::jobId()),
        );

        $queue = config('socialhub.queue') ?: null;

        dispatch($job->onQueue($queue));

        $scheduled->forceFill([
            'status' => ScheduledPostStatus::Queued->value,
        ])->save();
    }

    /**
     * Fail one variant without touching its siblings.
     */
    public function failVariant(PostVariant $variant, \App\Services\Social\Data\UserFacingError $error, ?string $jobId, int $attempt): void
    {
        $variant->forceFill([
            'status' => PostVariantStatus::Failed->value,
            'error_message' => $error->userMessage,
        ])->save();

        $scheduled = $variant->scheduledPost()->first();

        if ($scheduled !== null && ! $scheduled->statusEnum()->isTerminal()) {
            $scheduled->forceFill([
                'status' => ScheduledPostStatus::Failed->value,
                'last_error' => $error->userMessage,
                'processed_at' => now(),
            ])->save();
        }

        StructuredLog::warning('publishing.variant_rejected', [
            'job_id' => $jobId,
            'organization_id' => $variant->post?->tenant_id,
            'post_id' => $variant->post_id,
            'variant_id' => $variant->getKey(),
            'provider' => $variant->provider?->value,
            'status' => PostVariantStatus::Failed->value,
            'attempt' => $attempt,
            'error_code' => $error->code,
            'retry_in_seconds' => null,
        ]);

        PostFailed::dispatch($variant, $error, $jobId, $attempt);
    }

    private function capabilityError(PostVariant $variant): ?\App\Services\Social\Data\UserFacingError
    {
        $provider = $variant->provider;

        if ($provider === null) {
            return \App\Services\Social\Data\UserFacingError::make(
                'provider_unknown',
                'This variant does not name a social network, so it cannot be published.',
                'Post variant has a null provider.',
                false,
                'Choose a network for this variant, then try again.',
            );
        }

        $key = $provider->value;

        try {
            $capabilities = $this->capabilitiesFor($key);
        } catch (Throwable) {
            return \App\Services\Social\Data\UserFacingError::make(
                'provider_unknown',
                sprintf('%s is not available on this deployment, so nothing was published.', $provider->label()),
                sprintf('No capability matrix is registered for provider "%s".', $key),
                false,
                'Contact an administrator to enable this network.',
                ['provider' => $key],
            );
        }

        return $this->gate->evaluate($variant, $capabilities, $key, $provider->label());
    }

    private function capabilitiesFor(string $provider): ProviderCapabilities
    {
        if ($this->registry->isImplemented($provider)) {
            try {
                return $this->registry->get($provider)->getSupportedFeatures();
            } catch (Throwable) {
                // Fall through to the static matrix: a provider that cannot be
                // instantiated must still be gated rather than dispatched blind.
            }
        }

        $overrides = (array) config(sprintf('socialhub.providers.%s.capabilities_override', $provider), []);

        if (! PlatformCapabilities::supports($provider)) {
            return (new ProviderCapabilities)->withOverrides($overrides);
        }

        return PlatformCapabilities::for($provider)->withOverrides($overrides);
    }

    private function tenantFor(Post $post): ?Tenant
    {
        if ($post->relationLoaded('tenant')) {
            $tenant = $post->getRelation('tenant');

            return $tenant instanceof Tenant ? $tenant : null;
        }

        return $post->tenant()->first();
    }
}
