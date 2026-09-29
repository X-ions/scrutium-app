<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Models\Post;

/**
 * Derives the parent post's status from the state of its variants.
 *
 * A post is only `published` when every variant reached a network. A single
 * failed variant on an otherwise successful post leaves the post in `failed`
 * so the composer shows the partial result rather than a green tick, while
 * `hasPublishedVariant()` still reports the truth for anyone asking "did
 * anything go out?".
 */
class PostStatusResolver
{
    public function resolve(Post $post): PostStatus
    {
        $statuses = $this->variantStatuses($post);

        if ($statuses === []) {
            return $post->status instanceof PostStatus ? $post->status : PostStatus::Draft;
        }

        $total = count($statuses);
        $published = $this->count($statuses, PostVariantStatus::Published);
        $failed = $this->count($statuses, PostVariantStatus::Failed);
        $inFlight = $this->count($statuses, PostVariantStatus::Publishing);
        $scheduled = $this->count($statuses, PostVariantStatus::Scheduled);
        $waiting = $this->count($statuses, PostVariantStatus::Pending);

        if ($published === $total) {
            return PostStatus::Published;
        }

        if ($this->count($statuses, PostVariantStatus::Cancelled) === $total) {
            return PostStatus::Cancelled;
        }

        if ($inFlight > 0) {
            return PostStatus::Publishing;
        }

        if ($failed > 0 && ($published > 0 || $failed === $total)) {
            return PostStatus::Failed;
        }

        if ($published > 0 && ($scheduled + $waiting) > 0) {
            return PostStatus::Publishing;
        }

        if ($scheduled > 0 && $published === 0) {
            return PostStatus::Scheduled;
        }

        return PostStatus::Draft;
    }

    /**
     * Persist the derived status on the post, skipping the write when nothing
     * changed so a hot retry loop does not churn `updated_at`.
     */
    public function sync(Post $post): PostStatus
    {
        $post->unsetRelation('variants');
        $post->loadMissing('variants');

        $resolved = $this->resolve($post);

        if ($post->status !== $resolved) {
            $post->status = $resolved;
            $post->save();
        }

        if ($resolved === PostStatus::Published && $post->published_at === null) {
            $post->forceFill(['published_at' => now()])->save();
        }

        return $resolved;
    }

    /**
     * @return list<PostVariantStatus>
     */
    private function variantStatuses(Post $post): array
    {
        $relation = $post->relationLoaded('variants') ? 'variants' : null;

        if ($relation === null) {
            $post->loadMissing('variants');
        }

        return $post->getRelation('variants')
            ->map(fn ($variant): PostVariantStatus => $variant->status instanceof PostVariantStatus
                ? $variant->status
                : PostVariantStatus::Pending)
            ->values()
            ->all();
    }

    /**
     * @param  list<PostVariantStatus>  $statuses
     */
    private function count(array $statuses, PostVariantStatus $status): int
    {
        return count(array_filter($statuses, static fn (PostVariantStatus $value): bool => $value === $status));
    }
}
