<?php

declare(strict_types=1);

namespace App\Events\Engagement;

use App\Models\SocialAccount;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A connected account's credential reached a terminal state (expired or revoked)
 * and the workspace has been notified once.
 */
class SocialAccountTokenInvalidated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SocialAccount $account,
        public readonly string $reason,
        public readonly bool $revoked = false,
    ) {}
}
