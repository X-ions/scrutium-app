<?php

declare(strict_types=1);

namespace App\Events\Engagement;

use App\Models\Comment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A comment landed in the inbox for the first time.
 *
 * Dispatched only on a genuine create, never on a re-sync or a redelivered
 * webhook, so a listener cannot turn one comment into several notifications.
 */
class NewCommentReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Comment $comment,
        public readonly string $source,
    ) {}

    public function sourceIsWebhook(): bool
    {
        return $this->source === 'webhook';
    }
}
