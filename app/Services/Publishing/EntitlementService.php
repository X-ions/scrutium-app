<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ScheduledPostStatus;
use App\Models\Post;
use App\Models\ScheduledPost;
use App\Models\Tenant;
use App\Services\Social\Data\UserFacingError;

/**
 * The single place plan limits are read.
 *
 * Controllers, jobs and commands ask this service rather than reading
 * `tenants.scheduled_posts_limit` themselves, so a limit change is one edit and
 * a limit breach is one error message. A null column means "unlimited"; a zero
 * means "not on any plan that includes this", which is a real state a trial can
 * be in and must not be silently treated as unlimited.
 */
class EntitlementService
{
    /**
     * Scheduled rows that still occupy a slot: a published, failed or cancelled
     * row no longer counts against the quota.
     *
     * @var list<ScheduledPostStatus>
     */
    private const ACTIVE_STATUSES = [
        ScheduledPostStatus::Pending,
        ScheduledPostStatus::Queued,
        ScheduledPostStatus::Processing,
    ];

    /**
     * @var array<string, int>
     */
    private array $usageCache = [];

    public function scheduledPostsLimit(Tenant $tenant): ?int
    {
        $limit = $tenant->getAttribute('scheduled_posts_limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        return max(0, (int) $limit);
    }

    public function scheduledPostsUsed(Tenant $tenant): int
    {
        $cacheKey = (string) $tenant->getKey();

        if (isset($this->usageCache[$cacheKey])) {
            return $this->usageCache[$cacheKey];
        }

        return $this->usageCache[$cacheKey] = $this->countActive($tenant);
    }

    public function remainingScheduledPosts(Tenant $tenant): ?int
    {
        $limit = $this->scheduledPostsLimit($tenant);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->scheduledPostsUsed($tenant));
    }

    public function canScheduleMore(Tenant $tenant, int $additional = 1): bool
    {
        $limit = $this->scheduledPostsLimit($tenant);

        if ($limit === null) {
            return true;
        }

        return ($this->scheduledPostsUsed($tenant) + $additional) <= $limit;
    }

    /**
     * @throws SchedulingLimitExceededException
     */
    public function assertCanScheduleMore(Tenant $tenant, int $additional = 1): void
    {
        if ($this->canScheduleMore($tenant, $additional)) {
            return;
        }

        $limit = (int) $this->scheduledPostsLimit($tenant);

        throw new SchedulingLimitExceededException(
            UserFacingError::make(
                'scheduling_limit_reached',
                sprintf(
                    'This workspace has reached its limit of %d scheduled %s. Remove a scheduled post or upgrade the plan to publish more.',
                    $limit,
                    $limit === 1 ? 'post' : 'posts',
                ),
                sprintf('Tenant %d has %d active scheduled posts against a limit of %d.', $tenant->getKey(), $this->scheduledPostsUsed($tenant), $limit),
                false,
                'Cancel or publish an existing scheduled post, or upgrade the workspace plan.',
                ['limit' => $limit, 'used' => $this->scheduledPostsUsed($tenant)],
            ),
            $limit,
            $this->scheduledPostsUsed($tenant),
        );
    }

    /**
     * Forget memoised usage after a write so the next check reads the truth.
     */
    public function flush(): void
    {
        $this->usageCache = [];
    }

    public function forgetTenant(Tenant|int|string|null $tenant): void
    {
        if ($tenant instanceof Tenant) {
            unset($this->usageCache[(string) $tenant->getKey()]);

            return;
        }

        if ($tenant !== null) {
            unset($this->usageCache[(string) $tenant]);
        }
    }

    /**
     * A post that is about to be published immediately still consumes a slot
     * while it is queued, so it is counted the same way as a future one.
     */
    public function willConsumeSlot(Post $post, int $variantCount): bool
    {
        $tenant = $post->relationLoaded('tenant') ? $post->getRelation('tenant') : $post->tenant();

        if (! $tenant instanceof Tenant) {
            return true;
        }

        return ! $this->canScheduleMore($tenant, $variantCount);
    }

    private function countActive(Tenant $tenant): int
    {
        return ScheduledPost::query()
            ->withoutGlobalScopes()
            ->whereHas(
                'postVariant',
                fn ($query) => $query->withoutGlobalScopes()
                    ->whereHas('post', fn ($post) => $post->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())),
            )
            ->whereIn('status', array_map(
                static fn (ScheduledPostStatus $status): string => $status->value,
                self::ACTIVE_STATUSES,
            ))
            ->count();
    }
}
