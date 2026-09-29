<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;
use Throwable;

/**
 * Provider-side throttling, either from the local token bucket or an upstream
 * HTTP 429. `retryAfter` is seconds the queue job should be released for.
 */
final class RateLimitException extends SocialProviderException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $provider,
        public readonly int $retryAfter,
        ?UserFacingError $userFacingError = null,
        ?Throwable $previous = null,
        array $context = [],
    ) {
        parent::__construct(
            sprintf('Provider "%s" is rate limited; retry in %d second(s).', $provider, $retryAfter),
            $userFacingError ?? UserFacingError::make(
                'rate_limited',
                sprintf('%s is asking us to slow down. The post will be retried automatically.', $provider),
                sprintf('Local token bucket exhausted for provider "%s".', $provider),
                true,
                'No action needed; the job is requeued with a delay.',
                ['provider' => $provider],
            ),
            $previous,
            ['provider' => $provider, 'retry_after' => $retryAfter] + $context,
        );
    }
}
