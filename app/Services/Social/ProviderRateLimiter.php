<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Exceptions\Social\RateLimitException;
use App\Services\Social\Support\RateLimitState;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-provider, per-tenant Redis token bucket.
 *
 * Fails OPEN when Redis is unreachable: a publish must never be crashed by the
 * throttle store. The failure is logged as a warning (without the bucket key's
 * tenant secrets) so it stays visible in monitoring.
 */
class ProviderRateLimiter
{
    /**
     * Atomic bucket refill-and-consume. Returns [allowed, retry_after_seconds].
     */
    private const LUA = <<<'LUA'
    local capacity = tonumber(ARGV[1])
    local refill = tonumber(ARGV[2])
    local cost = tonumber(ARGV[3])
    local now = tonumber(ARGV[4])
    local ttl = tonumber(ARGV[5])

    local bucket = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
    local tokens = tonumber(bucket[1])
    local ts = tonumber(bucket[2])

    if tokens == nil then
      tokens = capacity
      ts = now
    end

    if now > ts then
      tokens = math.min(capacity, tokens + ((now - ts) / 1000) * refill)
      ts = now
    end

    if tokens >= cost then
      tokens = tokens - cost
      redis.call('HSET', KEYS[1], 'tokens', tokens, 'ts', ts)
      redis.call('PEXPIRE', KEYS[1], ttl)
      return {1, 0}
    end

    redis.call('HSET', KEYS[1], 'tokens', tokens, 'ts', ts)
    redis.call('PEXPIRE', KEYS[1], ttl)
    local deficit = cost - tokens
    local wait = math.ceil((deficit / refill) * 1000)
    return {0, wait}
    LUA;

    private bool $degraded = false;

    public function __construct(
        private readonly ?RedisFactory $redis = null,
        private readonly array $limits = [],
        private readonly int $bucketTtlSeconds = 3600,
    ) {}

    /**
     * @param  array<string, array{capacity?: int, refill_per_second?: float, cost?: int}>  $limits
     */
    public function limits(): array
    {
        return $this->limits;
    }

    public function limitFor(string $provider): array
    {
        $limit = array_merge(
            (array) ($this->limits['default'] ?? []),
            (array) ($this->limits[$provider] ?? []),
        );

        return [
            'capacity' => max(1, (int) ($limit['capacity'] ?? 60)),
            'refill_per_second' => max(0.001, (float) ($limit['refill_per_second'] ?? 1)),
            'cost' => max(1, (int) ($limit['cost'] ?? 1)),
        ];
    }

    public function key(string $provider, int|string|null $tenantId): string
    {
        return sprintf('ratelimit:%s:%s', $provider, $tenantId ?? 'global');
    }

    /**
     * Consume one call from the bucket.
     *
     * @throws RateLimitException when the bucket is empty
     */
    public function acquire(string $provider, int|string|null $tenantId, int $cost = 1): void
    {
        $limit = $this->limitFor($provider);
        $cost = max(1, $cost * $limit['cost']);

        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        try {
            $result = $connection->eval(
                self::LUA,
                1,
                $this->key($provider, $tenantId),
                $limit['capacity'],
                $limit['refill_per_second'],
                $cost,
                $this->nowMilliseconds(),
                $this->bucketTtlSeconds * 1000,
            );
        } catch (Throwable $e) {
            $this->degrade($provider, $e);

            return;
        }

        if (! is_array($result) || (int) ($result[0] ?? 0) !== 1) {
            $waitMilliseconds = (int) ($result[1] ?? 1000);
            $retryAfter = (int) max(1, ceil($waitMilliseconds / 1000));

            throw new RateLimitException($provider, $retryAfter);
        }
    }

    /**
     * Read the current bucket state without consuming a token.
     */
    public function peek(string $provider, int|string|null $tenantId): ?RateLimitState
    {
        $connection = $this->connection();

        if ($connection === null) {
            return null;
        }

        try {
            $bucket = $connection->hgetall($this->key($provider, $tenantId));
        } catch (Throwable $e) {
            $this->degrade($provider, $e);

            return null;
        }

        if (! is_array($bucket) || $bucket === []) {
            return null;
        }

        return new RateLimitState(
            retryAfter: 0,
            remaining: isset($bucket['tokens']) ? (int) $bucket['tokens'] : null,
            source: 'local-bucket',
        );
    }

    public function clear(string $provider, int|string|null $tenantId): bool
    {
        $connection = $this->connection();

        if ($connection === null) {
            return false;
        }

        try {
            return (bool) $connection->del($this->key($provider, $tenantId));
        } catch (Throwable $e) {
            $this->degrade($provider, $e);

            return false;
        }
    }

    /**
     * @return array{capacity: int, refill_per_second: float, cost: int}
     */
    public function describe(string $provider): array
    {
        return $this->limitFor($provider);
    }

    protected function nowMilliseconds(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function connection(): ?Connection
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            return $this->redis->connection();
        } catch (Throwable $e) {
            $this->degrade('*', $e);

            return null;
        }
    }

    private function degrade(string $provider, Throwable $e): void
    {
        if ($this->degraded) {
            return;
        }

        $this->degraded = true;

        Log::warning('SocialHub rate limiter is unavailable; provider calls are failing open.', [
            'provider' => $provider,
            'error' => $e->getMessage(),
        ]);
    }
}
