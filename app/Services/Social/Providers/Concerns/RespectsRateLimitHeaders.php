<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use App\Exceptions\Social\RateLimitException;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\Support\RateLimitState;
use Illuminate\Http\Client\Response;

/**
 * Interprets upstream throttling headers so a provider can react before it
 * burns through a 429, and so a 429 carries the exact delay the platform asked
 * for. The delay is always at least one second — never an immediate retry.
 */
trait RespectsRateLimitHeaders
{
    abstract protected function providerKey(): string;

    /**
     * Read the throttling state advertised on a successful response.
     */
    protected function rateLimitStateFrom(Response $response, ?int $fallback = null): RateLimitState
    {
        return RateLimitState::fromResponse($response, $fallback ?? $this->fallbackRetryAfter());
    }

    /**
     * Build the exception for a 429 using the provider's own headers.
     */
    protected function rateLimitExceptionFrom(Response $response): RateLimitException
    {
        $state = RateLimitState::fromResponse($response, $this->fallbackRetryAfter());

        return new RateLimitException(
            $this->providerKey(),
            $state->retryAfter,
            UserFacingError::make(
                'rate_limited',
                sprintf('%s is asking us to slow down. The post will be retried automatically.', $this->platformName()),
                sprintf('%s returned HTTP 429 (retry after %ds, source %s).', $this->platformKey(), $state->retryAfter, $state->source ?? 'default'),
                true,
                'No action needed; the job is requeued with a delay.',
                ['retry_after' => $state->retryAfter, 'source' => $state->source],
            ),
            null,
            ['rate_limit' => $state->toArray()],
        );
    }

    /**
     * Assert the response is usable, converting 429 into a RateLimitException.
     */
    protected function assertNotRateLimited(Response $response): void
    {
        if ($response->status() === 429) {
            throw $this->rateLimitExceptionFrom($response);
        }
    }

    /**
     * Seconds to wait when the platform gives us no usable header at all.
     */
    protected function fallbackRetryAfter(): int
    {
        return (int) config('socialhub.rate_limits.'.$this->providerKey().'.fallback_retry_after', 60);
    }
}
