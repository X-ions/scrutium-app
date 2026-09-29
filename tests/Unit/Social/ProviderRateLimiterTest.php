<?php

declare(strict_types=1);

use App\Exceptions\Social\RateLimitException;
use App\Services\Social\ProviderRateLimiter;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

/**
 * A Redis connection double that replays a scripted set of `eval` results and
 * records the arguments the limiter passes, so the bucket's real call
 * signature is asserted without touching a live Redis.
 */
function fakeRedisConnection(array $results): Connection
{
    $connection = Mockery::mock(Connection::class);

    $connection->shouldReceive('eval')->andReturn(...$results);

    return $connection;
}

function fakeRedisFactory(Connection $connection): RedisFactory
{
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->andReturn($connection);

    return $factory;
}

it('allows a call while the bucket has tokens', function () {
    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory(fakeRedisConnection([[1, 0]])),
        limits: ['facebook' => ['capacity' => 5, 'refill_per_second' => 1]],
    );

    $limiter->acquire('facebook', 42);

    expect(true)->toBeTrue();
});

it('allows repeated calls while the bucket has tokens', function () {
    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory(fakeRedisConnection([[1, 0], [1, 0]])),
        limits: ['facebook' => ['capacity' => 5, 'refill_per_second' => 1]],
    );

    $limiter->acquire('facebook', 42);
    $limiter->acquire('facebook', 42);

    expect(true)->toBeTrue();
});

it('throws RateLimitException once the bucket is exhausted', function () {
    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory(fakeRedisConnection([[1, 0], [0, 30000]])),
        limits: ['facebook' => ['capacity' => 1, 'refill_per_second' => 1]],
    );

    $limiter->acquire('facebook', 42);

    $limiter->acquire('facebook', 42);
})->throws(RateLimitException::class);

it('carries a retryAfter of at least one second on exhaustion', function () {
    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory(fakeRedisConnection([[0, 30000]])),
        limits: ['facebook' => ['capacity' => 1, 'refill_per_second' => 1]],
    );

    try {
        $limiter->acquire('facebook', 42);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(30)
            ->and($e->provider)->toBe('facebook')
            ->and($e->userFacingError->retryable)->toBeTrue()
            ->and($e->userFacingError->code)->toBe('rate_limited');
    }
});

it('never reports a zero retryAfter, which would cause an instant retry', function () {
    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory(fakeRedisConnection([[0, 0]])),
        limits: ['facebook' => ['capacity' => 1, 'refill_per_second' => 1]],
    );

    try {
        $limiter->acquire('facebook', 42);
        $this->fail('Expected a RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBeGreaterThanOrEqual(1);
    }
});

it('keys the bucket by provider and tenant so tenants cannot exhaust each other', function () {
    $connection = Mockery::mock(Connection::class);
    $keys = [];

    $connection->shouldReceive('eval')->andReturnUsing(
        function (...$args) use (&$keys) {
            // script, key count, key, capacity, refill, cost
            $keys[] = $args[2];

            return [1, 0];
        },
    );

    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory($connection),
        limits: ['facebook' => ['capacity' => 5, 'refill_per_second' => 1]],
    );

    $limiter->acquire('facebook', 1);
    $limiter->acquire('facebook', 2);
    $limiter->acquire('facebook', null);

    expect($keys)->toBe([
        'ratelimit:facebook:1',
        'ratelimit:facebook:2',
        'ratelimit:facebook:global',
    ]);
});

it('multiplies the call cost by the configured per-call cost', function () {
    $connection = Mockery::mock(Connection::class);

    $connection->shouldReceive('eval')->once()->withArgs(function (...$args): bool {
        // LUA, key count, key, capacity, refill, cost
        return (int) $args[5] === 3;
    })->andReturn([1, 0]);

    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory($connection),
        limits: ['facebook' => ['capacity' => 100, 'refill_per_second' => 1, 'cost' => 3]],
    );

    $limiter->acquire('facebook', 42);

    expect(true)->toBeTrue();
});

it('scopes buckets per provider', function () {
    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory(fakeRedisConnection([])),
        limits: [
            'facebook' => ['capacity' => 5, 'refill_per_second' => 1],
            'tiktok' => ['capacity' => 5, 'refill_per_second' => 1],
        ],
    );

    expect($limiter->key('facebook', 7))->toBe('ratelimit:facebook:7')
        ->and($limiter->key('tiktok', 7))->toBe('ratelimit:tiktok:7');
});

it('falls back to the default limit for an unlisted provider', function () {
    $limiter = new ProviderRateLimiter(
        redis: null,
        limits: ['default' => ['capacity' => 42, 'refill_per_second' => 0.5, 'cost' => 1]],
    );

    expect($limiter->limitFor('unknown_platform'))->toBe([
        'capacity' => 42,
        'refill_per_second' => 0.5,
        'cost' => 1,
    ]);
});

it('fails open when Redis is unavailable so a publish is never crashed', function () {
    Log::spy();

    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

    $limiter = new ProviderRateLimiter(
        redis: $factory,
        limits: ['facebook' => ['capacity' => 1, 'refill_per_second' => 0.001]],
    );

    // The bucket is sized for a single call; without fail-open the second
    // acquire would throw and take the publish job down with it.
    $limiter->acquire('facebook', 42);
    $limiter->acquire('facebook', 42);
    $limiter->acquire('facebook', 42);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'failing open')
            && ($context['provider'] ?? null) !== null
            && ! str_contains(json_encode($context), 'token'));
});

it('fails open when no Redis factory is bound at all', function () {
    $limiter = new ProviderRateLimiter(
        redis: null,
        limits: ['facebook' => ['capacity' => 1, 'refill_per_second' => 0.001]],
    );

    $limiter->acquire('facebook', 42);
    $limiter->acquire('facebook', 42);
})->throwsNoExceptions();

it('fails open when the bucket operation itself throws mid-flight', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('eval')->andThrow(new RuntimeException('READONLY'));

    $limiter = new ProviderRateLimiter(
        redis: fakeRedisFactory($connection),
        limits: ['facebook' => ['capacity' => 1, 'refill_per_second' => 0.001]],
    );

    $limiter->acquire('facebook', 42);
})->throwsNoExceptions();

it('clears a bucket and reports the remaining tokens', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('hgetall')->andReturn(['tokens' => '17']);
    $connection->shouldReceive('del')->andReturn(1);

    $limiter = new ProviderRateLimiter(redis: fakeRedisFactory($connection));

    $state = $limiter->peek('facebook', 42);

    expect($state->remaining)->toBe(17)
        ->and($limiter->clear('facebook', 42))->toBeTrue();
});
