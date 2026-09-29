<?php

declare(strict_types=1);

namespace App\Events\Engagement;

use App\Models\CommentReply;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A queued reply reached the network.
 */
class CommentReplySent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CommentReply $reply,
        public readonly ?string $providerReplyId = null,
    ) {}
}
