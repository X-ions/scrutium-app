<?php

declare(strict_types=1);

namespace App\Events\Engagement;

use App\Models\CommentReply;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A queued reply could not be delivered.
 *
 * `reason` is a user-facing sentence. It is never an exception message that has
 * not been vetted, and it never contains credential material.
 */
class CommentReplyFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CommentReply $reply,
        public readonly string $reason,
        public readonly bool $retryable = false,
    ) {}
}
