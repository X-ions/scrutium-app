<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostVariantStatus;
use App\Enums\ScheduledPostStatus;
use App\Jobs\Publishing\PublishPostJob;
use App\Models\PostVariant;
use App\Models\ScheduledPost;
use App\Services\Publishing\StructuredLog;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Claims due scheduled posts and hands each one to a worker.
 *
 * Two scheduler ticks can run concurrently, so a row is claimed with a
 * `lockForUpdate` and a status flip inside a transaction: the loser of the race
 * sees `queued` and leaves it alone. A row left in `queued`/`processing` well
 * past its scheduled time means a worker died holding it, so those are
 * recovered rather than stranded.
 */
class PublishDuePostsCommand extends Command
{
    protected $signature = 'publish:due {--limit=200 : Maximum rows claimed per tick} {--stale-minutes=10 : Age after which a claimed row counts as abandoned}';

    protected $description = 'Claim due scheduled posts and dispatch one publishing job per variant';

    /**
     * A claimed row older than this is assumed to belong to a worker that died.
     */
    public const STALE_MINUTES = 10;

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stale = max(1, (int) $this->option('stale-minutes'));

        $recovered = $this->recoverStalled($stale);
        $claimed = $this->claimDue($limit);

        if ($recovered > 0 || $claimed > 0) {
            $this->components->info(sprintf(
                'publish:due claimed %d due %s and recovered %d stalled %s.',
                $claimed,
                $claimed === 1 ? 'post' : 'posts',
                $recovered,
                $recovered === 1 ? 'row' : 'rows',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return int number of rows dispatched
     */
    private function claimDue(int $limit): int
    {
        $candidates = ScheduledPost::query()
            ->withoutGlobalScopes()
            ->where('status', ScheduledPostStatus::Pending->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;

        foreach ($candidates as $id) {
            $row = $this->claim($id);

            if ($row === null) {
                continue;
            }

            $this->dispatch($row);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Flip a single row from pending to queued while holding its row lock.
     *
     * @return ScheduledPost|null the claimed row, or null when another tick won
     */
    private function claim(int $scheduledPostId): ?ScheduledPost
    {
        try {
            return DB::transaction(function () use ($scheduledPostId): ?ScheduledPost {
                $row = ScheduledPost::query()
                    ->withoutGlobalScopes()
                    ->whereKey($scheduledPostId)
                    ->lockForUpdate()
                    ->first();

                if ($row === null || $row->statusEnum() !== ScheduledPostStatus::Pending) {
                    return null;
                }

                $row->forceFill([
                    'status' => ScheduledPostStatus::Queued->value,
                    'job_id' => StructuredLog::jobId(),
                ])->save();

                return $row;
            });
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Re-queue rows a dead worker left mid-flight. The variant is put back to
     * `scheduled` so the job's idempotency check, not this command, decides
     * whether the provider call still has to happen. The row is left `pending`
     * so the claim path below dispatches it exactly once, in this same tick.
     *
     * @return int number of rows recovered
     */
    private function recoverStalled(int $staleMinutes): int
    {
        $cutoff = now()->subMinutes($staleMinutes);

        $stalled = ScheduledPost::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [ScheduledPostStatus::Queued->value, ScheduledPostStatus::Processing->value])
            ->where('updated_at', '<', $cutoff)
            ->get();

        $recovered = 0;

        foreach ($stalled as $scheduled) {
            $variant = PostVariant::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->find($scheduled->post_variant_id);

            if ($variant === null || $variant->trashed() || $variant->statusEnum() === PostVariantStatus::Cancelled) {
                $scheduled->forceFill([
                    'status' => ScheduledPostStatus::Cancelled->value,
                    'processed_at' => now(),
                ])->save();

                continue;
            }

            if ($variant->isPublished() || ($variant->provider_post_id !== null && $variant->provider_post_id !== '')) {
                $scheduled->forceFill([
                    'status' => ScheduledPostStatus::Published->value,
                    'processed_at' => now(),
                ])->save();

                continue;
            }

            $scheduled->forceFill([
                'status' => ScheduledPostStatus::Pending->value,
                'scheduled_at' => now(),
                'job_id' => StructuredLog::jobId(),
            ])->save();

            $variant->forceFill([
                'status' => PostVariantStatus::Scheduled->value,
            ])->save();

            $recovered++;
        }

        return $recovered;
    }

    private function dispatch(ScheduledPost $scheduled): void
    {
        $job = new PublishPostJob(
            postVariantId: (int) $scheduled->post_variant_id,
            scheduledPostId: (int) $scheduled->getKey(),
            jobId: (string) ($scheduled->job_id ?: StructuredLog::jobId()),
        );

        $queue = config('socialhub.queue') ?: null;

        dispatch($job->onQueue($queue));

        StructuredLog::write('publishing.scheduled_dispatched', [
            'job_id' => $job->jobId,
            'organization_id' => null,
            'post_id' => null,
            'variant_id' => $scheduled->post_variant_id,
            'provider' => null,
            'status' => ScheduledPostStatus::Queued->value,
            'attempt' => (int) $scheduled->attempts,
            'error_code' => null,
            'retry_in_seconds' => null,
            'scheduled_post_id' => $scheduled->getKey(),
            'scheduled_at' => $scheduled->scheduled_at?->toIso8601String(),
        ]);
    }
}
