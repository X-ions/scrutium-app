<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostVariantStatus;
use App\Exceptions\Social\SocialProviderException;
use App\Models\PostVariant;
use App\Models\User;
use App\Services\Publishing\PublishingService;
use App\Services\Publishing\SchedulingLimitExceededException;
use App\Services\Publishing\StructuredLog;
use App\Services\Publishing\UnsupportedVariantException;
use Illuminate\Console\Command;

/**
 * Re-queues one failed variant from the composer's Retry action.
 *
 * The capability gate runs again before anything is dispatched: the reason a
 * variant failed may have been fixed by reconnecting the account or changing
 * the content, and retrying blind would burn the whole budget on an error the
 * user can already see the cause of.
 */
class RetryFailedVariantsCommand extends Command
{
    protected $signature = 'publishing:retry-variant
        {variantId : The post variant id to re-queue}
        {--actor= : User id recorded as the actor for the retry}';

    protected $description = 'Re-queue a single failed post variant, failing fast when the network still cannot accept it';

    public function handle(PublishingService $publishing): int
    {
        $variantId = (int) $this->argument('variantId');

        if ($variantId <= 0) {
            $this->components->error('A numeric post variant id is required.');

            return self::INVALID;
        }

        $variant = PostVariant::query()->withTrashed()->find($variantId);

        if ($variant === null) {
            $this->components->error(sprintf('No post variant with id %d was found.', $variantId));

            return self::FAILURE;
        }

        if ($variant->trashed() || $variant->statusEnum() === PostVariantStatus::Cancelled) {
            $this->components->error('That variant was cancelled and cannot be retried.');

            return self::FAILURE;
        }

        if ($variant->isPublished() || ($variant->provider_post_id !== null && $variant->provider_post_id !== '')) {
            $this->components->warn('That variant is already published; nothing was re-queued.');

            return self::SUCCESS;
        }

        $actor = $this->resolveActor($variant);

        try {
            $scheduled = $publishing->retryVariant($variant, $actor);
        } catch (UnsupportedVariantException $e) {
            $this->components->error('This variant cannot be retried yet.');
            $this->line('  '.$e->getMessage());

            if ($e->error->remediation !== null) {
                $this->line('  Next: '.$e->error->remediation);
            }

            return self::FAILURE;
        } catch (SchedulingLimitExceededException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (SocialProviderException $e) {
            $this->components->error($e->userMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Variant %d re-queued as job %s.', $variant->getKey(), (string) $scheduled->job_id));

        StructuredLog::write('publishing.manual_retry', [
            'job_id' => $scheduled->job_id,
            'organization_id' => $variant->post?->tenant_id,
            'post_id' => $variant->post_id,
            'variant_id' => $variant->getKey(),
            'provider' => $variant->provider?->value,
            'status' => $scheduled->statusEnum()->value,
            'attempt' => (int) $variant->retry_count,
            'error_code' => null,
            'retry_in_seconds' => null,
        ]);

        return self::SUCCESS;
    }

    /**
     * The audit trail needs a user; a scheduled command has none, so the post
     * author is the honest stand-in for a click nobody authenticated.
     */
    private function resolveActor(PostVariant $variant): User
    {
        $actorId = $this->option('actor');

        if ($actorId !== null) {
            $actor = User::query()->find((int) $actorId);

            if ($actor instanceof User) {
                return $actor;
            }
        }

        $author = $variant->post?->user;

        if ($author instanceof User) {
            return $author;
        }

        return new User(['id' => null]);
    }
}
