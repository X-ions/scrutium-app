<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenExpiredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\Support\RateLimitState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\TimeoutException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper over Laravel's HTTP factory for all provider traffic.
 *
 * Responsibilities: consume the rate-limit bucket before each call, apply a
 * timeout and a User-Agent, and translate transport/HTTP failures into the
 * social exception hierarchy. Authorization headers are never logged and never
 * copied into exception messages or context.
 */
class ProviderHttpClient
{
    private const NEVER_LOG_HEADERS = [
        'authorization',
        'x-access-token',
        'proxy-authorization',
        'cookie',
        'set-cookie',
    ];

    private array $extraHeaders = [];

    public function __construct(
        private readonly ProviderRateLimiter $limiter,
        private string $provider = 'unknown',
        private int|string|null $tenantId = null,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly ?string $userAgent = null,
        private readonly ?string $apiVersion = null,
        private ?string $accessToken = null,
        private bool $throttle = true,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function withAccessToken(?string $accessToken): static
    {
        $clone = clone $this;
        $clone->accessToken = $accessToken;

        return $clone;
    }

    public function withTenant(int|string|null $tenantId): static
    {
        $clone = clone $this;
        $clone->tenantId = $tenantId;

        return $clone;
    }

    public function withoutThrottle(): static
    {
        $clone = clone $this;
        $clone->throttle = false;

        return $clone;
    }

    public function get(string $url, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $r) => $r->get($url, $query));
    }

    public function post(string $url, array $payload = []): Response
    {
        return $this->send(fn (PendingRequest $r) => $r->post($url, $payload));
    }

    public function postForm(string $url, array $payload = []): Response
    {
        return $this->send(fn (PendingRequest $r) => $r->post($url, $payload));
    }

    public function postJson(string $url, array $payload = []): Response
    {
        return $this->send(fn (PendingRequest $r) => $r->post($url, $payload));
    }

    public function put(string $url, array $payload = []): Response
    {
        return $this->send(fn (PendingRequest $r) => $r->put($url, $payload));
    }

    public function delete(string $url, array $payload = []): Response
    {
        return $this->send(fn (PendingRequest $r) => $r->delete($url, $payload));
    }

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->extraHeaders[$name] = $value;

        return $clone;
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     *
     * @throws RateLimitException
     * @throws ProviderApiException
     */
    protected function send(callable $callback): Response
    {
        if ($this->throttle) {
            $this->limiter->acquire($this->provider, $this->tenantId);
        }

        $request = Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withUserAgent($this->userAgent ?? $this->defaultUserAgent())
            ->withHeaders($this->extraHeaders);

        if ($this->accessToken !== null && $this->accessToken !== '') {
            $request = $request->withToken($this->accessToken);
        }

        if ($this->apiVersion !== null) {
            $request = $request->withHeaders(['X-Api-Version' => $this->apiVersion]);
        }

        try {
            $response = $callback($request);
        } catch (TimeoutException $e) {
            throw new ProviderApiException(
                $this->provider,
                504,
                null,
                UserFacingError::make(
                    'provider_timeout',
                    sprintf('%s did not respond in time. The post will be retried.', ucfirst($this->provider)),
                    'The provider request exceeded its timeout.',
                    true,
                    'No action needed; the job is requeued with a delay.',
                ),
                $e,
            );
        } catch (ConnectionException $e) {
            throw new ProviderApiException(
                $this->provider,
                502,
                null,
                UserFacingError::make(
                    'provider_unreachable',
                    sprintf('%s could not be reached. The post will be retried.', ucfirst($this->provider)),
                    'The provider endpoint could not be reached.',
                    true,
                    'No action needed; the job is requeued with a delay.',
                ),
                $e,
            );
        } catch (Throwable $e) {
            throw new ProviderApiException($this->provider, 500, null, null, $e);
        }

        if ($response->status() === 429) {
            throw $this->rateLimitException($response);
        }

        $this->throwForAuthFailure($response);

        return $response;
    }

    /**
     * Build the exception for a 429 using the upstream headers.
     */
    protected function rateLimitException(Response $response): RateLimitException
    {
        $state = RateLimitState::fromResponse($response, $this->fallbackRetryAfter());

        return new RateLimitException(
            $this->provider,
            $state->retryAfter,
            UserFacingError::make(
                'rate_limited',
                sprintf('%s is asking us to slow down. The post will be retried automatically.', ucfirst($this->provider)),
                sprintf('Provider "%s" returned HTTP 429.', $this->provider),
                true,
                'No action needed; the job is requeued with a delay.',
                ['retry_after' => $state->retryAfter, 'source' => $state->source],
            ),
            null,
            ['retry_after' => $state->retryAfter, 'rate_limit' => $state->toArray()],
        );
    }

    /**
     * @throws TokenRevokedException|TokenExpiredException
     */
    protected function throwForAuthFailure(Response $response): void
    {
        if ($response->status() !== 401) {
            return;
        }

        $code = $this->providerErrorCode($response);

        if (in_array($code, ['invalid_grant', 'unauthorized_client', '190', '463'], true)) {
            throw new TokenRevokedException($this->provider, null, ['provider_code' => $code]);
        }

        throw new TokenExpiredException($this->provider, null, ['provider_code' => $code]);
    }

    /**
     * Seconds to wait when the platform gives us no usable header at all.
     * Never zero: an unthrottled immediate retry against a throttling API is
     * never correct.
     */
    protected function fallbackRetryAfter(): int
    {
        return max(1, (int) config(sprintf('socialhub.rate_limits.%s.fallback_retry_after', $this->provider), 60));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function providerErrorBody(Response $response): ?array
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        return is_array($body) ? $body : null;
    }

    protected function providerErrorCode(Response $response): ?string
    {
        $body = $this->providerErrorBody($response);

        if ($body === null) {
            return null;
        }

        $error = $body['error'] ?? null;

        if (is_array($error) && isset($error['code'])) {
            return (string) $error['code'];
        }

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return null;
    }

    /**
     * Build a ProviderApiException for a non-2xx response that is not auth or
     * rate limiting. The upstream body is summarised, never attached verbatim.
     */
    protected function apiException(Response $response, ?UserFacingError $userFacingError = null): ProviderApiException
    {
        $status = $response->status();
        $code = $this->providerErrorCode($response);

        return new ProviderApiException(
            $this->provider,
            $status,
            $code,
            $userFacingError ?? UserFacingError::make(
                'provider_api_error',
                sprintf('%s rejected the request. Nothing was published.', ucfirst($this->provider)),
                sprintf('Provider "%s" returned HTTP %d.', $this->provider, $status),
                $status >= 500,
                $status >= 500
                    ? 'No action needed; the job is requeued with a delay.'
                    : 'Review the variant content and try again.',
                ['status' => $status, 'provider_code' => $code],
            ),
            null,
            ['status' => $status, 'provider_code' => $code],
        );
    }

    /**
     * Log an upstream failure without any credential material.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logFailure(string $operation, Response $response, array $context = []): void
    {
        Log::warning('SocialHub provider request failed.', array_merge([
            'provider' => $this->provider,
            'operation' => $operation,
            'status' => $response->status(),
            'provider_code' => $this->providerErrorCode($response),
        ], $context));
    }

    /**
     * @return list<string>
     */
    protected function redactedHeaders(): array
    {
        return self::NEVER_LOG_HEADERS;
    }

    private function defaultUserAgent(): string
    {
        return sprintf('SocialHubCMS/1.0 (+%s)', config('app.url', 'https://localhost'));
    }
}
