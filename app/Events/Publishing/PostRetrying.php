<?php

declare(strict_types=1);

namespace App\Events\Publishing;

use App\Models\PostVariant;
use App\Services\Social\Data\UserFacingError;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostRetrying
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PostVariant $variant,
        public readonly UserFacingError $error,
        public readonly int $attempt,
        public readonly int $retryInSeconds,
        public readonly ?string $jobId,
    ) {}
}
