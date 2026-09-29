<?php

declare(strict_types=1);

namespace App\Jobs\Publishing;

use App\Enums\PostVariantStatus;
use App\Enums\PublishingAttemptStatus;
use App\Enums\ScheduledPostStatus;
use App\Events\Publishing\PostPublished;
use App\Events\Publishing\PostRetrying;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\SocialProviderException;
use App\Exceptions\Social\TokenExpiredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Models\PostVariant;
use App\Models\PublishingAttempt;
use App\Models\ScheduledPost;
use App\Services\Publishing\DeadLetterService;
use App\Services\Publishing\PayloadRedactor;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\StructuredLog;
use App\Services\Publishing\VariantCapabilityGate;
use App\Services\Social\Data\ProviderPost;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publishes one variant to one network.
 *
 * A worker may die at any point in here — after the variant is marked
 * `publishing` but before the API call, or after the API call but before the
 * provider id is written. Every step is therefore written to be safe to repeat:
 * the lock key is the variant, the early return on a published variant costs
 * one indexed read, and the only thing that ever marks success is a provider
 * post id that is already persisted.
 */
class PublishPostJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * How long a claimed lock may survive a hard worker kill before another
     * worker is allowed to take the variant over. Comfortably longer than
     * {@see $timeout} so a slow publish is never stolen mid-flight.
     */
    public int $lockFor = 600;

    public function __construct(
        public readonly int $postVariantId,
        public readonly int $scheduledPostId,
        public readonly string $jobId,
    ) {
        $this->tries = (int) config('socialhub.publishing.max_attempts', 5);
        $this->timeout = (int) config('socialhub.publishing.timeout', 30) + 30;
        $this->onQueue(config('socialhub.queue') ?: null);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = array_values(array_filter(
            array_map('intval', (array) config('socialhub.publishing.backoff', [30, 120, 600])),
            static fn (int $seconds): bool => $seconds > 0,
        ));

        return $backoff === [] ? [30] : $backoff;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours((int) config('socialhub.publishing.retry_window_hours', 24));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->postVariantId))->expireAfter($this->lockFor)->dontRelease()];
    }

    public function handle(
        SocialProviderRegistry $registry,
        VariantCapabilityGate $gate,
        PostStatusResolver $statuses,
        DeadLetterService $deadLetters,
    ): void {
        $variant = $this->findVariant();

        if ($variant === null) {
            $this->releaseSchedule('cancelled');

            return;
        }

        if ($this->alreadyPublished($variant)) {
            $this->log('publishing.already_published', $variant, [
                'status' => $variant->statusEnum()->value,
                'provider_post_id' => $variant->provider_post_id,
            ]);

            return;
        }

        $this->log('publishing.attempt_started', $variant, ['status' => $variant->statusEnum()->value]);

        $attemptNumber = $this->nextAttemptNumber($variant);

        $this->markVariantPublishing($variant);
        $this->markScheduleProcessing();

        $attempt = $this->openAttempt($variant, $attemptNumber);
        $account = $variant->socialAccount()->first();

        if ($account === null) {
            $this->closeAttempt($attempt, PublishingAttemptStatus::Failed, [
                'error_code' => 'account_missing',
                'error_message' => 'The connected account no longer exists.',
            ]);
            $deadLetters->fail($variant, UserFacingError::make(
                'account_missing',
                'The account this variant targets is no longer connected. Nothing was published.',
                'The social account referenced by the variant was deleted.',
                false,
                'Reconnect the account, or remove this network from the post.',
            ), $this->jobId, $attemptNumber);

            return;
        }

        $provider = (string) ($variant->provider?->value ?? '');

        try {
            $capabilities = $this->capabilitiesFor($registry, $provider);
        } catch (Throwable) {
            $capabilities = new \App\Services\Social\Contracts\ProviderCapabilities;
        }

        $blockingError = $gate->evaluate($variant, $capabilities, $provider, $variant->provider?->label() ?? 'This network');

        if ($blockingError !== null) {
            $this->closeAttempt($attempt, PublishingAttemptStatus::Failed, [
                'error_code' => $blockingError->code,
                'error_message' => $blockingError->technicalMessage,
            ]);
            $deadLetters->fail($variant, $blockingError, $this->jobId, $attemptNumber);

            return;
        }

        try {
            $providerPost = $registry->get($provider)->publishPost($account, $variant);
        } catch (RateLimitException $e) {
            $this->handleRateLimit($variant, $attempt, $e, $deadLetters);

            return;
        } catch (UnsupportedCapabilityException|TokenExpiredException|TokenRevokedException|ProviderNotConfiguredException $e) {
            $this->closeAttempt($attempt, PublishingAttemptStatus::Failed, [
                'error_code' => $e->userFacingError?->code ?? 'not_publishable',
                'error_message' => $e->userFacingError?->technicalMessage ?? $e->getMessage(),
            ]);
            $deadLetters->fail(
                $variant,
                $e->userFacingError ?? UserFacingError::make(
                    'not_publishable',
                    'This post cannot be published to that network. Nothing was published.',
                    $e->getMessage(),
                    false,
                ),
                $this->jobId,
                $attemptNumber,
            );

            return;
        } catch (ProviderApiException $e) {
            $this->handleProviderFailure($variant, $attempt, $e, $deadLetters);

            return;
        } catch (SocialProviderException $e) {
            $this->closeAttempt($attempt, PublishingAttemptStatus::Failed, [
                'error_code' => $e->userFacingError?->code ?? 'provider_error',
                'error_message' => $e->userFacingError?->technicalMessage ?? $e->getMessage(),
            ]);
            $deadLetters->fail(
                $variant,
                $e->userFacingError ?? UserFacingError::make('provider_error', 'The network rejected this post.', $e->getMessage(), false),
                $this->jobId,
                $attemptNumber,
            );

            return;
        } catch (Throwable $e) {
            $this->log('publishing.unexpected_error', $variant, [
                'error_code' => 'unexpected',
                'exception' => $e::class,
            ], 'error');

            $this->closeAttempt($attempt, PublishingAttemptStatus::Failed, [
                'error_code' => 'unexpected',
                'error_message' => $e->getMessage(),
            ]);

            if ($this->budgetExhausted($attemptNumber)) {
                $deadLetters->fail(
                    $variant,
                    UserFacingError::make(
                        'publishing_error',
                        'Something went wrong while publishing and every retry was used. Nothing was published.',
                        $e->getMessage(),
                        false,
                        'Retry the variant, or contact support if it keeps failing.',
                    ),
                    $this->jobId,
                    $attemptNumber,
                );

                return;
            }

            $this->release($this->delayFor($attemptNumber));

            return;
        }

        $this->completeAttempt($attempt, $variant, $providerPost);
        $this->markVariantPublished($variant, $providerPost);
        $this->markSchedulePublished();
        $this->syncPostStatus($variant, $statuses);

        $this->log('publishing.variant_published', $variant, [
            'status' => PostVariantStatus::Published->value,
            'provider_post_id' => $providerPost->providerPostId,
        ]);

        PostPublished::dispatch(
            $variant,
            $providerPost->providerPostId,
            $providerPost->permalink,
            $this->jobId,
            $attemptNumber,
        );
    }

    /**
     * Last line of defence: anything that still escapes (a timeout, a dead
     * database) must not leave a variant stuck in `publishing` forever.
     */
    public function failed(?Throwable $e): void
    {
        try {
            $variant = $this->findVariant();

            if ($variant === null || $variant->isPublished()) {
                return;
            }

            $attemptNumber = max(1, $this->nextAttemptNumber($variant) - 1);

            app(DeadLetterService::class)->fail(
                $variant,
                UserFacingError::make(
                    'publishing_failed',
                    sprintf('Publishing gave up after %d attempts. Nothing was published to this network.', $attemptNumber),
                    $e?->getMessage() ?? 'The queue job failed without an exception message.',
                    false,
                    'Retry this variant, or contact support with the job id if it keeps failing.',
                    ['job_id' => $this->jobId],
                ),
                $this->jobId,
                $attemptNumber,
            );
        } catch (Throwable $inner) {
            StructuredLog::error('publishing.dead_letter_error', [
                'job_id' => $this->jobId,
                'variant_id' => $this->postVariantId,
                'error_code' => 'dead_letter_failed',
            ]);
        }
    }

    /**
     * A 429 is not a failed attempt: the variant stays `publishing`, the job is
     * released for exactly the delay the provider asked for, and the retry
     * budget is untouched so a busy afternoon cannot exhaust a post's attempts.
     */
    private function handleRateLimit(
        PostVariant $variant,
        PublishingAttempt $attempt,
        RateLimitException $e,
        DeadLetterService $deadLetters,
    ): void {
        $retryAfter = max(1, $e->retryAfter);
        $error = $e->userFacingError ?? UserFacingError::make(
            'rate_limited',
            'The network is asking us to slow down. The post will be retried automatically.',
            $e->getMessage(),
            true,
        );

        $attempt->forceFill([
            'status' => PublishingAttemptStatus::RateLimited->value,
            'error_code' => $error->code,
            'error_message' => $error->technicalMessage,
            'rate_limit_reset_at' => now()->addSeconds($retryAfter),
            'response_payload' => PayloadRedactor::redact($e->context),
            'completed_at' => now(),
        ])->save();

        $this->markVariantPublishing($variant, message: $error->userMessage);
        $this->releaseSchedule('queued', $error->userMessage);

        $this->log('publishing.rate_limited', $variant, [
            'status' => PostVariantStatus::Publishing->value,
            'error_code' => $error->code,
            'retry_in_seconds' => $retryAfter,
        ], 'warning');

        PostRetrying::dispatch($variant, $error, $this->nextAttemptNumber($variant), $retryAfter, $this->jobId);

        $this->release($retryAfter);
    }

    private function handleProviderFailure(
        PostVariant $variant,
        PublishingAttempt $attempt,
        ProviderApiException $e,
        DeadLetterService $deadLetters,
    ): void {
        $error = $e->userFacingError ?? UserFacingError::make(
            'provider_api_error',
            'The network rejected this post. Nothing was published.',
            $e->getMessage(),
            $e->status >= 500,
        );

        $retryable = $error->retryable || $e->status >= 500;

        $this->closeAttempt($attempt, PublishingAttemptStatus::Failed, [
            'error_code' => $error->code,
            'error_message' => $error->technicalMessage,
            'response_payload' => PayloadRedactor::redact($e->context + ['status' => $e->status]),
        ]);

        $attemptNumber = (int) $attempt->attempt_number;

        if (! $retryable || $this->budgetExhausted($attemptNumber)) {
            $deadLetters->fail($variant, $error, $this->jobId, $attemptNumber);

            return;
        }

        $delay = $this->delayFor($attemptNumber);

        $variant->forceFill([
            'status' => PostVariantStatus::Publishing->value,
            'error_message' => $error->userMessage,
            'retry_count' => $attemptNumber,
        ])->save();

        $this->releaseSchedule('queued', $error->userMessage);

        $this->log('publishing.retry_scheduled', $variant, [
            'status' => PostVariantStatus::Publishing->value,
            'error_code' => $error->code,
            'attempt' => $attemptNumber,
            'retry_in_seconds' => $delay,
        ], 'warning');

        PostRetrying::dispatch($variant, $error, $attemptNumber, $delay, $this->jobId);

        $this->release($delay);
    }

    private function completeAttempt(PublishingAttempt $attempt, PostVariant $variant, ProviderPost $post): void
    {
        $attempt->forceFill([
            'status' => PublishingAttemptStatus::Success->value,
            'response_payload' => PayloadRedactor::redact($post->toArray()),
            'completed_at' => now(),
        ])->save();
    }

    private function openAttempt(PostVariant $variant, int $attemptNumber): PublishingAttempt
    {
        $scheduled = $this->findSchedule();

        $attempt = new PublishingAttempt([
            'post_variant_id' => $variant->getKey(),
            'scheduled_post_id' => $scheduled?->getKey(),
            'attempt_number' => $attemptNumber,
            'status' => PublishingAttemptStatus::Processing->value,
            'request_payload' => PayloadRedactor::redact([
                'caption' => $variant->caption,
                'media_count' => count((array) $variant->media_ids),
                'hashtags' => $variant->hashtags,
                'mentions' => $variant->mentions,
                'scheduled_at' => $variant->scheduled_at?->toIso8601String(),
            ]),
            'started_at' => now(),
        ]);

        $attempt->save();

        $this->bumpScheduleAttempts();

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function closeAttempt(PublishingAttempt $attempt, PublishingAttemptStatus $status, array $attributes = []): void
    {
        $attempt->forceFill($attributes + [
            'status' => $status->value,
            'completed_at' => now(),
        ])->save();
    }

    private function markVariantPublishing(PostVariant $variant, ?string $message = null): void
    {
        $variant->forceFill(array_filter([
            'status' => PostVariantStatus::Publishing->value,
            'error_message' => $message,
        ], static fn (mixed $value): bool => $value !== null))->save();
    }

    private function markVariantPublished(PostVariant $variant, ProviderPost $post): void
    {
        $variant->forceFill([
            'status' => PostVariantStatus::Published->value,
            'provider_post_id' => $post->providerPostId,
            'provider_post_url' => $post->permalink,
            'published_at' => $post->publishedAt ?? now(),
            'error_message' => null,
        ])->save();
    }

    private function markScheduleProcessing(): void
    {
        $scheduled = $this->findSchedule();

        if ($scheduled === null) {
            return;
        }

        $scheduled->forceFill([
            'status' => ScheduledPostStatus::Processing->value,
            'job_id' => $this->jobId,
        ])->save();
    }

    private function markSchedulePublished(): void
    {
        $scheduled = $this->findSchedule();

        if ($scheduled === null) {
            return;
        }

        $scheduled->forceFill([
            'status' => ScheduledPostStatus::Published->value,
            'last_error' => null,
            'processed_at' => now(),
        ])->save();
    }

    private function releaseSchedule(string $status, ?string $error = null): void
    {
        $scheduled = $this->findSchedule();

        if ($scheduled === null || $scheduled->statusEnum()->isTerminal()) {
            return;
        }

        $scheduled->forceFill([
            'status' => $status,
            'job_id' => $this->jobId,
            'last_error' => $error,
        ])->save();
    }

    private function bumpScheduleAttempts(): void
    {
        ScheduledPost::query()
            ->whereKey($this->scheduledPostId)
            ->update(['attempts' => DB::raw('attempts + 1')]);
    }

    private function syncPostStatus(PostVariant $variant, PostStatusResolver $statuses): void
    {
        $post = $variant->post;

        if ($post === null) {
            return;
        }

        $statuses->sync($post);
    }

    /**
     * The attempt number is derived from the durable record rather than the
     * worker's in-memory counter, so a worker restart cannot rewind the budget.
     */
    private function nextAttemptNumber(PostVariant $variant): int
    {
        $recorded = (int) PublishingAttempt::query()
            ->withoutGlobalScopes()
            ->where('post_variant_id', $variant->getKey())
            ->whereIn('status', [
                PublishingAttemptStatus::Processing->value,
                PublishingAttemptStatus::Failed->value,
                PublishingAttemptStatus::Success->value,
            ])
            ->max('attempt_number');

        return max(1, $recorded + 1, $this->attempts());
    }

    private function budgetExhausted(int $attemptNumber): bool
    {
        return $attemptNumber >= $this->tries;
    }

    private function delayFor(int $attemptNumber): int
    {
        $backoff = $this->backoff();
        $index = max(0, $attemptNumber - 1);

        return (int) ($backoff[$index] ?? end($backoff));
    }

    private function findVariant(): ?PostVariant
    {
        $variant = PostVariant::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->find($this->postVariantId);

        if ($variant === null) {
            $this->logRaw('publishing.variant_missing', ['variant_id' => $this->postVariantId], 'warning');

            return null;
        }

        if ($variant->trashed() || $variant->statusEnum() === PostVariantStatus::Cancelled) {
            $this->logRaw('publishing.variant_cancelled', [
                'variant_id' => $variant->getKey(),
                'status' => $variant->statusEnum()->value,
            ], 'warning');

            return null;
        }

        return $variant;
    }

    /**
     * A variant that already carries a provider post id is live. Re-publishing
     * it would create a duplicate on the network, so the job returns without
     * touching the API.
     */
    private function alreadyPublished(PostVariant $variant): bool
    {
        return $variant->isPublished()
            || ($variant->provider_post_id !== null && $variant->provider_post_id !== '');
    }

    private function findSchedule(): ?ScheduledPost
    {
        return ScheduledPost::query()
            ->withoutGlobalScopes()
            ->where('post_variant_id', $this->postVariantId)
            ->first();
    }

    private function capabilitiesFor(SocialProviderRegistry $registry, string $provider): \App\Services\Social\Contracts\ProviderCapabilities
    {
        if ($provider !== '' && $registry->isImplemented($provider)) {
            return $registry->get($provider)->getSupportedFeatures();
        }

        return new \App\Services\Social\Contracts\ProviderCapabilities;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $event, PostVariant $variant, array $extra = [], string $level = 'info'): void
    {
        $this->logRaw($event, $extra + [
            'job_id' => $this->jobId,
            'organization_id' => $variant->post?->tenant_id,
            'post_id' => $variant->post_id,
            'variant_id' => $variant->getKey(),
            'provider' => $variant->provider?->value,
        ], $level);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logRaw(string $event, array $context, string $level = 'info'): void
    {
        match ($level) {
            'error' => StructuredLog::error($event, $context),
            'warning' => StructuredLog::warning($event, $context),
            default => StructuredLog::write($event, $context),
        };
    }
}
