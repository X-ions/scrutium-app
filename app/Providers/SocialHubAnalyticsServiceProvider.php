<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\AggregateSnapshotsCommand;
use App\Console\Commands\PartitionAnalyticsMetricsCommand;
use App\Console\Commands\SyncAnalyticsCommand;
use App\Services\Analytics\AnalyticsIngestService;
use App\Services\Analytics\MetricNormalizer;
use App\Services\Analytics\RetentionPolicy;
use App\Services\Analytics\SnapshotAggregator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the analytics engine: ingestion, aggregation, the dashboard read model
 * and the maintenance commands.
 *
 * The schedule is registered here rather than in `routes/console.php` so the
 * analytics cadence travels with the code that implements it. The two cadences
 * are deliberately different:
 *
 * - **Hourly** sync. `socialhub:analytics:sync` fans out one job per connected
 *   account and staggers them across the hour by account id, so a burst of
 *   accounts never lands on a provider in the same second.
 * - **Daily** aggregation, offset past the hour so the last sync of the day has
 *   landed before the roll-up reads it. Snapshots are idempotent, so a late
 *   arrival simply updates the same rows.
 * - **Monthly** partition maintenance, on the first of the month, extending the
 *   partition set well ahead of the data that will need it.
 */
class SocialHubAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricNormalizer::class, static fn (): MetricNormalizer => new \App\Services\Analytics\MetricNormalizer);

        $this->app->singleton(RetentionPolicy::class, static fn (): RetentionPolicy => new \App\Services\Analytics\RetentionPolicy);

        $this->app->singleton(AnalyticsIngestService::class, static fn ($app): AnalyticsIngestService => new \App\Services\Analytics\AnalyticsIngestService(
            normalizer: $app->make(\App\Services\Analytics\MetricNormalizer::class),
            retention: $app->make(\App\Services\Analytics\RetentionPolicy::class),
        ));

        $this->app->singleton(SnapshotAggregator::class, static fn (): SnapshotAggregator => new \App\Services\Analytics\SnapshotAggregator);
    }

    public function boot(Schedule $schedule): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAnalyticsCommand::class,
                AggregateSnapshotsCommand::class,
                PartitionAnalyticsMetricsCommand::class,
            ]);
        }

        $schedule->command(SyncAnalyticsCommand::class, ['--days' => 7])
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command(AggregateSnapshotsCommand::class, ['--all-periods', '--days' => 35])
            ->dailyAt('03:15')
            ->withoutOverlapping();

        $schedule->command(PartitionAnalyticsMetricsCommand::class)
            ->monthlyOn(1, '02:40')
            ->withoutOverlapping();
    }
}
