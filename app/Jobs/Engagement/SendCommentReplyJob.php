<?php

declare(strict_types=1);

namespace App\Jobs\Engagement;

use App\Enums\CommentSyncStatus;
use App\Models\CommentReply;
use App\Models\Tenant;
use App\Services\Engagement\NotificationService;
use App\Services\Engagement\ReplyService;
use App\Services\Social\SocialProviderRegistry;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends one queued comment reply.
 *
 * A reply whose network is simply throttled or down is retried with backoff. A
 * reply the platform cannot perform at all is recorded as failed and not
 * retried, because retrying an unsupported capability can never succeed.
 */
final class SendCommentReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        public readonly int $commentReplyId,
        public readonly ?int $tenantId = null,
    ) {}

    public function handle(
        SocialProviderRegistry $registry,
        ReplyService $replies,
        NotificationService $notifications,
    ): void {
        if ($this->tenantId !== null) {
            TenantContext::set(Tenant::query()->find($this->tenantId));
        }

        $reply = CommentReply::query()->withoutGlobalScopes()->find($this->commentReplyId);

        if ($reply === null) {
            return;
        }

        // A job that runs twice must not double-post.
        if ($reply->status === CommentSyncStatus::Sent->value) {
            return;
        }

        unset($registry, $notifications);

        $replies->send($reply);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('socialhub.comments.reply_failed', [
            'comment_reply_id' => $this->commentReplyId,
            'exception' => $exception::class,
        ]);
    }
}
