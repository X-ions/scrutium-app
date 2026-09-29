<?php

declare(strict_types=1);

namespace App\Events\Publishing;

use App\Models\PostVariant;
use App\Services\Social\Data\UserFacingError;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PostVariant $variant,
        public readonly UserFacingError $error,
        public readonly ?string $jobId,
        public readonly int $attempt,
        /**
         * True once the retry budget is spent: the only case that raises a
         * tenant notification. A mid-flight retry must not page anyone.
         */
        public readonly bool $terminal = true,
    ) {}

    public function userMessage(): string
    {
        return $this->error->userMessage;
    }
}
