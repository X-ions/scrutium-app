<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\PrunePublishingAttemptsCommand;
use App\Console\Commands\PublishDuePostsCommand;
use App\Console\Commands\RetryFailedVariantsCommand;
use App\Services\Publishing\DeadLetterService;
use App\Services\Publishing\EntitlementService;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublishingService;
use App\Services\Publishing\VariantCapabilityGate;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the publishing engine: services, job listeners and console commands.
 *
 * The notification listeners under `app/Listeners/Publishing` are picked up by
 * Laravel's event discovery, so they are not registered here as well — doing
 * both would fire every listener twice and send the tenant two notifications
 * for one failure.
 *
 * Kept separate from the provider layer's service provider so the provider
 * configuration can be reasoned about without the queue attached to it.
 */
class SocialHubPublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntitlementService::class, static fn (): EntitlementService => new EntitlementService);
        $this->app->singleton(PostStatusResolver::class, static fn (): PostStatusResolver => new PostStatusResolver);
        $this->app->singleton(VariantCapabilityGate::class, static fn (): VariantCapabilityGate => new VariantCapabilityGate);

        $this->app->singleton(DeadLetterService::class, static fn ($app): DeadLetterService => new DeadLetterService(
            statuses: $app->make(PostStatusResolver::class),
        ));

        $this->app->singleton(PublishingService::class, static fn ($app): PublishingService => new PublishingService(
            registry: $app->make(SocialProviderRegistry::class),
            gate: $app->make(VariantCapabilityGate::class),
            statuses: $app->make(PostStatusResolver::class),
            entitlements: $app->make(EntitlementService::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishDuePostsCommand::class,
                RetryFailedVariantsCommand::class,
                PrunePublishingAttemptsCommand::class,
            ]);
        }
    }

    /**
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [
            PublishingService::class,
            DeadLetterService::class,
            EntitlementService::class,
            PostStatusResolver::class,
            VariantCapabilityGate::class,
        ];
    }
}
