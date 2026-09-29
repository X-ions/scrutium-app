<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Trims the webhook event table.
 *
 * Rows are kept long enough to cover a provider's own redelivery window — the
 * idempotency guarantee depends on the event id still being present when a
 * retry arrives, so pruning too aggressively would let duplicates through.
 */
class SocialhubWebhooksPruneCommand extends Command
{
    protected $signature = 'socialhub:webhooks:prune {--days=30 : Keep events for this many days} {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete old webhook event records';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = WebhookEvent::query()
            ->withoutGlobalScopes()
            ->where('processed', true)
            ->where('created_at', '<', $cutoff);

        $processed = (clone $query)->count();
        $unprocessed = WebhookEvent::query()
            ->withoutGlobalScopes()
            ->where('processed', false)
            ->where('created_at', '<', $cutoff)
            ->count();

        if ($dryRun) {
            $this->info(sprintf(
                'Would delete %d processed event(s) and %d unprocessed event(s) older than %d days.',
                $processed,
                $unprocessed,
                $days,
            ));

            return self::SUCCESS;
        }

        if ($processed === 0 && $unprocessed === 0) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        // An unprocessed event is the record of work still owed; it is kept
        // however old it is, because deleting it would silently drop an
        // update the network really sent.
        $deleted = (clone $query)->orderBy('created_at')->limit(1000)->delete();

        $this->info(sprintf('Deleted %d webhook event(s) older than %d days.', $deleted, $days));

        if ($unprocessed > 0) {
            $this->warn(sprintf('Kept %d unprocessed event(s); they still represent work owed.', $unprocessed));
        }

        return self::SUCCESS;
    }
}
