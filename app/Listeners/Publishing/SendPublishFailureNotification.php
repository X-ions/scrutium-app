<?php

declare(strict_types=1);

namespace App\Listeners\Publishing;

use App\Events\Publishing\PostFailed;
use App\Models\SocialHubNotification;
use App\Models\Tenant;

/**
 * Raises the tenant-level alert for a publish that will not succeed on its own.
 *
 * Only terminal failures notify: a variant that is being retried in three
 * minutes has not reached a decision yet, and a notification per attempt would
 * bury the failures that actually need a human.
 */
class SendPublishFailureNotification
{
    public function handle(PostFailed $event): void
    {
        if (! $event->terminal) {
            return;
        }

        $variant = $event->variant;
        $post = $variant->post;
        $tenantId = $post?->tenant_id;

        if ($post === null || $tenantId === null) {
            return;
        }

        $platform = $variant->provider?->label() ?? 'This network';
        $title = sprintf('%s could not publish to %s', $post->title ?: 'A post', $platform);

        SocialHubNotification::create([
            'tenant_id' => $tenantId,
            'user_id' => $post->user_id,
            'type' => 'post.failed',
            'title' => $title,
            'message' => $event->userMessage(),
            'data' => [
                'post_id' => $post->getKey(),
                'post_variant_id' => $variant->getKey(),
                'provider' => $variant->provider?->value,
                'error_code' => $event->error->code,
                'attempt' => $event->attempt,
                'job_id' => $event->jobId,
                'remediation' => $event->error->remediation,
            ],
            'action_url' => sprintf('/posts/%d', $post->getKey()),
            'is_read' => false,
            'priority' => 'high',
        ]);
    }

    /**
     * A tenant that no longer exists cannot be notified; the attempt row is the
     * remaining record, so this is deliberately a no-op rather than a throw.
     */
    public function tenantStillExists(int|Tenant|null $tenant): bool
    {
        return $tenant === null || $tenant->exists;
    }
}
