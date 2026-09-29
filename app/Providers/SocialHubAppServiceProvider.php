<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Engagement\CommentQueryService;
use App\Services\Engagement\NotificationService;
use App\Services\Engagement\ReplyService;
use App\Services\Engagement\TokenRefreshService;
use App\Services\Engagement\WebhookIngestService;
use App\Services\Social\OAuth\AccountConnectionService;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the SocialHub application services and registers the recurring
 * engagement work.
 *
 * Nothing in `register()` touches the database, so the container can be built
 * inside a console command, a queued job or a test without a resolved tenant.
 */
class SocialHubAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\Media\MediaStorage::class, fn ($app) => new \App\Services\Media\MediaStorage($app['filesystem']));
        $this->app->singleton(\App\Services\Media\Thumbnailer::class);
        $this->app->singleton(\App\Services\Media\MediaUploadService::class);
        $this->app->singleton(\App\Services\Media\MediaLibraryService::class);
        $this->app->singleton(NotificationService::class);
        $this->app->singleton(CommentQueryService::class);
        $this->app->singleton(ReplyService::class);
        $this->app->singleton(WebhookIngestService::class);
        $this->app->singleton(TokenRefreshService::class);
        $this->app->singleton(AccountConnectionService::class);

        $this->app->alias(SocialProviderRegistry::class, 'socialhub.registry');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\SocialhubCommentsSyncCommand::class,
                \App\Console\Commands\SocialhubTokensRefreshCommand::class,
                \App\Console\Commands\SocialhubWebhooksPruneCommand::class,
            ]);
        }
    }
}
