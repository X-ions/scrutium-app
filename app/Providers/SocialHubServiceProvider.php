<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Social\Contracts\OauthStateStore;
use App\Services\Social\OAuth\DatabaseOauthStateStore;
use App\Services\Social\OAuth\OAuthCoordinator;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\ProviderRateLimiter;
use App\Services\Social\SocialProviderRegistry;
use App\Services\Social\Support\AccountTokenResolver;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the social provider layer from `config/socialhub.php`.
 *
 * Registering a new provider is a config entry plus one class — nothing else in
 * the application needs to change.
 */
class SocialHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRateLimiter::class, function ($app): ProviderRateLimiter {
            return self::buildRateLimiter($app);
        });

        $this->app->singleton(AccountTokenResolver::class, static fn (): AccountTokenResolver => new AccountTokenResolver);

        $this->app->bind(OauthStateStore::class, static fn (): OauthStateStore => new DatabaseOauthStateStore);

        $this->app->singleton(OAuthCoordinator::class, static fn ($app): OAuthCoordinator => new OAuthCoordinator(
            registry: $app->make(SocialProviderRegistry::class),
            states: $app->make(OauthStateStore::class),
            config: $app->make('config'),
        ));

        $this->app->singleton(SocialProviderRegistry::class, function ($app): SocialProviderRegistry {
            return new SocialProviderRegistry(
                container: $app,
                definitions: (array) config('socialhub.providers', []),
            );
        });

        $this->app->bind(ProviderHttpClient::class, static fn ($app): ProviderHttpClient => new ProviderHttpClient(
            limiter: $app->make(ProviderRateLimiter::class),
            timeout: (int) config('socialhub.publishing.timeout', 30),
            connectTimeout: (int) config('socialhub.publishing.connect_timeout', 10),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\SocialHubDoctorCommand::class,
                \App\Console\Commands\SocialHubMakeProviderCommand::class,
            ]);
        }
    }

    /**
     * The bucket is sized to hold a full window of requests at the configured
     * refill rate, doubled so a burst cannot immediately exhaust the limiter.
     */
    public static function buildRateLimiter($app): ProviderRateLimiter
    {
        $capacity = (float) config('socialhub.rate_limits.default.capacity', 60);
        $refill = max(0.001, (float) config('socialhub.rate_limits.default.refill_per_second', 1));

        return new ProviderRateLimiter(
            redis: self::resolveRedisFactory($app),
            limits: (array) config('socialhub.rate_limits', []),
            bucketTtlSeconds: (int) ceil(($capacity / $refill) * 2),
        );
    }

    /**
     * Resolve the Redis factory without assuming the phpredis extension is     * installed. The rate limiter treats a null factory as "fail open", so a
     * deployment without Redis still publishes — it just publishes unthrottled
     * at the process level, and `socialhub:doctor` reports the gap.
     */
    private static function resolveRedisFactory($app): ?RedisFactory
    {
        if (! class_exists(\Redis::class) && ! extension_loaded('redis') && ! extension_loaded('phpredis')) {
            return null;
        }

        try {
            $factory = $app->make(RedisFactory::class);

            return $factory instanceof RedisFactory ? $factory : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [
            SocialProviderRegistry::class,
            ProviderRateLimiter::class,
            ProviderHttpClient::class,
            AccountTokenResolver::class,
            OauthStateStore::class,
            OAuthCoordinator::class,
        ];
    }
}
