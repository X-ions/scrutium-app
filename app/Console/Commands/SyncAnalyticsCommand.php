<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SocialAccountStatus;
use App\Jobs\Analytics\SyncAccountAnalyticsJob;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fans out one analytics sync job per connected account.
 *
 * The jobs are staggered across the hour by account id. A hundred accounts
 * polled on the same tick is a rate-limit incident waiting to happen; spacing
 * them out keeps each provider's quota roughly flat across the hour instead.
 *
 * Only `connected` accounts are queued. An account that is expired or revoked
 * would fail on the token and raise a notification the tenant cannot act on
 * without reconnecting, so it is left for the OAuth layer to surface instead.
 */
class SyncAnalyticsCommand extends Command
{
    protected $signature = 'socialhub:analytics:sync
        {--account= : Sync a single social account id}
        {--days=7 : How many days of history to request}
        {--no-stagger : Dispatch every job immediately (maintenance runs)}
        {--sync : Dispatch synchronously instead of queueing}';

    protected $description = 'Queue a per-account analytics sync, staggered across the hour';

    public function handle(): int
    {
        $query = SocialAccount::query()
            ->where('status', SocialAccountStatus::Connected->value);

        if ((int) $this->option('account') > 0) {
            $query->whereKey((int) $this->option('account'));
        }

        $days = max(1, (int) $this->option('days'));
        $stagger = ! $this->option('no-stagger');
        $sync = (bool) $this->option('sync');

        $accounts = $query->get();
        $this->line(sprintf('Found %d connected account(s) to sync.', $accounts->count()));

        $staggered = 0;

        foreach ($accounts as $account) {
            $delay = $stagger ? ($account->getKey() % 55) * 60 : 0;

            $job = (new SyncAccountAnalyticsJob($account->getKey(), $days))->delay($delay);

            $sync ? dispatch_sync($job) : dispatch($job);

            if ($delay > 0) {
                $staggered++;
            }
        }

        Log::info('SocialHub analytics sync dispatched.', [
            'accounts' => $accounts->count(),
            'staggered' => $staggered,
        ]);

        $this->line(sprintf('Dispatched %d job(s), %d of them staggered.', $accounts->count(), $staggered));

        return self::SUCCESS;
    }
}
