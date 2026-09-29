<?php

declare(strict_types=1);

namespace App\Events\Publishing;

use App\Enums\SocialPlatform;
use App\Models\PostVariant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PostVariant $variant,
        public readonly string $providerPostId,
        public readonly ?string $providerPostUrl,
        public readonly ?string $jobId,
        public readonly int $attempt,
    ) {}

    public function platform(): ?SocialPlatform
    {
        return $this->variant->provider;
    }
}
