<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\PruneWebhookEventsCommand;
use App\Console\Commands\RefreshExpiringTokensCommand;
use App\Console\Commands\SyncCommentsCommand;
use App\Services\Engagement\CommentQueryService;
use App\Services\Engagement\CommentSyncService;
use App\Services\Engagement\NotificationService;
use App\Services\Engagement\ReplyService;
use App\Services\Engagement\TokenRefreshService;
use App\Services\Engagement\WebhookIngestService;
use App\Services\Media\ImageSanitizer;
use App\Services\Media\MediaLibraryService;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUploadService;
use App\Services\Media\MediaValidator;
use App\Services\Media\Thumbnailer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the media library, the comments inbox, the webhook receiver and the
 * token lifecycle, and owns their schedule.
 *
 * The schedule lives here rather than in `routes/console.php` so the cadence
 * travels with the code that implements it. Three cadences, deliberately
 * different:
 *
 * - **Comments every 15 minutes.** Fast enough that a reply box is not showing
 *   yesterday's conversation, and staggered per account by
 *   `socialhub:comments:sync` so a hundred accounts never hit a provider in the
 *   same second.
 * - **Token refresh hourly**, inside a window of 24 hours. Hourly rather than
 *   "at expiry" because a refresh that starts at expiry has already lost the
 *   race; a 24-hour window means a token is normally renewed long before any
 *   provider call can see it lapse.
 * - **Webhook pruning nightly**, offset so it never runs in the same minute as
 *   the token sweep, and `withoutOverlapping` so a slow delete cannot stack.
 *
 * Every command is also `withoutOverlapping()`: the token refresh in particular
 * takes its own cache lock, and the two together mean an overlapping run is a
 * no-op rather than a double refresh.
 */
class SocialHubMediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaStorage::class, static fn ($app): MediaStorage => new MediaStorage($app->make('filesystem')));

        $this->app->singleton(ImageSanitizer::class, static fn (): ImageSanitizer => new ImageSanitizer);

        $this->app->singleton(MediaValidator::class, static fn ($app): MediaValidator => new MediaValidator(
            sanitizer: $app->make(ImageSanitizer::class),
        ));

        $this->app->singleton(Thumbnailer::class, static fn ($app): Thumbnailer => new Thumbnailer(
            storage: $app->make(MediaStorage::class),
        ));

        $this->app->singleton(MediaUploadService::class, static fn ($app): MediaUploadService => new MediaUploadService(
            validator: $app->make(MediaValidator::class),
            storage: $app->make(MediaStorage::class),
            thumbnails: $app->make(Thumbnailer::class),
        ));

        $this->app->singleton(MediaLibraryService::class, static fn ($app): MediaLibraryService => new MediaLibraryService(
            storage: $app->make(MediaStorage::class),
        ));

        $this->app->singleton(NotificationService::class, static fn (): NotificationService => new NotificationService);

        $this->app->singleton(CommentSyncService::class, static fn ($app): CommentSyncService => new CommentSyncService(
            registry: $app->make(\App\Services\Social\SocialProviderRegistry::class),
            notifications: $app->make(NotificationService::class),
        ));

        $this->app->singleton(ReplyService::class, static fn ($app): ReplyService => new ReplyService(
            registry: $app->make(\App\Services\Social\SocialProviderRegistry::class),
        ));

        $this->app->singleton(CommentQueryService::class, static fn (): CommentQueryService => new CommentQueryService);

        $this->app->singleton(WebhookIngestService::class, static fn (): WebhookIngestService => new WebhookIngestService);

        $this->app->singleton(TokenRefreshService::class, static fn (): TokenRefreshService => new TokenRefreshService);
    }

    public function boot(Schedule $schedule): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCommentsCommand::class,
                RefreshExpiringTokensCommand::class,
                PruneWebhookEventsCommand::class,
            ]);
        }

        $schedule->command(SyncCommentsCommand::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command(RefreshExpiringTokensCommand::class, ['--hours' => 24])
            ->hourlyAt(7)
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command(PruneWebhookEventsCommand::class, ['--days' => 30])
            ->dailyAt('04:25')
            ->withoutOverlapping();
    }

    /**
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [
            MediaStorage::class,
            MediaUploadService::class,
            MediaLibraryService::class,
            MediaValidator::class,
            Thumbnailer::class,
            NotificationService::class,
            CommentSyncService::class,
            CommentQueryService::class,
            ReplyService::class,
            WebhookIngestService::class,
            TokenRefreshService::class,
        ];
    }
}
