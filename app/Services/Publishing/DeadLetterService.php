<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\PublishingAttemptStatus;
use App\Enums\ScheduledPostStatus;
use App\Events\Publishing\PostFailed;
use App\Models\PostVariant;
use App\Models\PublishingAttempt;
use App\Models\ScheduledPost;
use App\Services\Social\Data\UserFacingError;

/**
 * The terminal state of a publish that will not succeed.
 *
 * Reaching here means the retry budget is spent or the failure is permanent, so
 * the variant is failed, an attempt row records the outcome even if the job died
 * before it could write one, the schedule is closed, the parent post is
 * re-resolved, and the tenant is notified. Nothing here throws: a dead letter is
 * the end of a failure path, and raising from it would only lose the record.
 */
class DeadLetterService
{
    public function __construct(
        private readonly PostStatusResolver $statuses,
    ) {}

    public function fail(PostVariant $variant, UserFacingError $error, ?string $jobId, int $attemptNumber): void
    {
        $variant->refresh();

        if ($variant->isPublished() || $variant->statusEnum() === PostVariantStatus::Cancelled) {
            return;
        }

        $variant->forceFill([
            'status' => PostVariantStatus::Failed->value,
            'error_message' => $error->userMessage,
            'retry_count' => max((int) $variant->retry_count, $attemptNumber),
        ])->save();

        $this->recordAttempt($variant, $error, $jobId, $attemptNumber);
        $this->closeSchedule($variant, $error);
        $this->syncPost($variant);

        StructuredLog::error('publishing.dead_lettered', [
            'job_id' => $jobId,
            'organization_id' => $variant->post?->tenant_id,
            'post_id' => $variant->post_id,
            'variant_id' => $variant->getKey(),
            'provider' => $variant->provider?->value,
            'status' => PostVariantStatus::Failed->value,
            'attempt' => $attemptNumber,
            'error_code' => $error->code,
            'retry_in_seconds' => null,
        ]);

        PostFailed::dispatch($variant, $error, $jobId, $attemptNumber, true);
    }

    /**
     * The job may have died before it opened an attempt, so the dead letter
     * writes one itself rather than trusting the row to exist.
     */
    private function recordAttempt(PostVariant $variant, UserFacingError $error, ?string $jobId, int $attemptNumber): void
    {
        $alreadyRecorded = PublishingAttempt::query()
            ->withoutGlobalScopes()
            ->where('post_variant_id', $variant->getKey())
            ->where('attempt_number', $attemptNumber)
            ->whereIn('status', [
                PublishingAttemptStatus::Failed->value,
                PublishingAttemptStatus::RateLimited->value,
            ])
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $attempt = new PublishingAttempt([
            'post_variant_id' => $variant->getKey(),
            'scheduled_post_id' => $this->scheduleId($variant),
            'attempt_number' => max(1, $attemptNumber),
            'status' => PublishingAttemptStatus::Failed->value,
            'request_payload' => PayloadRedactor::redact([
                'caption' => $variant->caption,
                'media_count' => count((array) $variant->media_ids),
            ]),
            'response_payload' => PayloadRedactor::redact([
                'error' => $error->toArray(),
                'job_id' => $jobId,
            ]),
            'error_code' => $error->code,
            'error_message' => $error->technicalMessage,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $attempt->save();
    }

    private function closeSchedule(PostVariant $variant, UserFacingError $error): void
    {
        $scheduled = ScheduledPost::query()
            ->withoutGlobalScopes()
            ->where('post_variant_id', $variant->getKey())
            ->first();

        if ($scheduled === null || $scheduled->statusEnum()->isTerminal()) {
            return;
        }

        $scheduled->forceFill([
            'status' => ScheduledPostStatus::Failed->value,
            'last_error' => $error->userMessage,
            'processed_at' => now(),
        ])->save();
    }

    private function scheduleId(PostVariant $variant): ?int
    {
        $scheduled = ScheduledPost::query()
            ->withoutGlobalScopes()
            ->where('post_variant_id', $variant->getKey())
            ->first();

        return $scheduled?->getKey();
    }

    private function syncPost(PostVariant $variant): void
    {
        $post = $variant->post;

        if ($post === null) {
            return;
        }

        $status = $this->statuses->sync($post);

        if ($status === PostStatus::Failed) {
            $post->forceFill(['published_at' => null])->save();
        }
    }
}
