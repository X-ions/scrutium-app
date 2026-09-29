<?php

declare(strict_types=1);

namespace App\Listeners\Publishing;

use App\Enums\PostStatus;
use App\Events\Publishing\PostPublished;
use App\Models\SocialHubNotification;

/**
 * Confirms a publish to the tenant once the whole post is live.
 *
 * A partial publish (one network up, another down) deliberately raises nothing:
 * the failure notification already explains the state, and a success toast on
 * top of it would be misleading.
 */
class SendPublishSuccessNotification
{
    public function handle(PostPublished $event): void
    {
        $variant = $event->variant;
        $post = $variant->post;

        if ($post === null || $post->tenant_id === null) {
            return;
        }

        $variants = $post->relationLoaded('variants') ? $post->getRelation('variants') : $post->variants()->get();

        $allPublished = $variants->isNotEmpty() && $variants->every(
            static fn ($candidate): bool => $candidate->isPublished(),
        );

        if (! $allPublished || $post->status !== PostStatus::Published) {
            return;
        }

        $platform = $variant->provider?->label() ?? 'the network';

        SocialHubNotification::create([
            'tenant_id' => $post->tenant_id,
            'user_id' => $post->user_id,
            'type' => 'post.published',
            'title' => sprintf('%s is live', $post->title ?: 'Your post'),
            'message' => sprintf('Published to %s and every other network on this post.', $platform),
            'data' => [
                'post_id' => $post->getKey(),
                'post_variant_id' => $variant->getKey(),
                'provider' => $variant->provider?->value,
                'provider_post_url' => $event->providerPostUrl,
            ],
            'action_url' => sprintf('/posts/%d', $post->getKey()),
            'is_read' => false,
            'priority' => 'normal',
        ]);
    }
}
