<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Engagement\CommentSyncJob;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

class SocialhubCommentsSyncCommand extends Command
{
    protected $signature = 'socialhub:comments:sync
        {--account= : Sync a single social account id}
        {--sync : Run the syncs inline instead of queueing them}
        {--no-stagger : Dispatch every account at once instead of spreading them out}';

    protected $description = 'Queue a comment sync for every connected social account';

    public function handle(): int
    {
        $accountId = $this->option('account');

        if ($accountId !== null) {
            $account = SocialAccount::query()->find((int) $accountId);

            if ($account === null) {
                $this->warn("Account {$accountId} was not found.");

                return self::SUCCESS;
            }

            if ((bool) $this->option('sync')) {
                $this->syncOne((int) $account->getKey());

                return self::SUCCESS;
            }

            CommentSyncJob::dispatch((int) $account->getKey());
            $this->info("Queued a comment sync for account {$accountId}.");

            return self::SUCCESS;
        }

        $accounts = $this->option('account') !== null
            ? SocialAccount::query()->whereKey((int) $this->option('account'))->get()
            : SocialAccount::query()->connected()->get();

        $inline = (bool) $this->option('sync');
        $stagger = ! $inline && ! $this->option('no-stagger');

        foreach ($accounts->values() as $index => $account) {
            if ($inline) {
                $this->syncOne((int) $account->getKey());

                continue;
            }

            // Spread the jobs out so a workspace with many accounts does not
            // hit every platform's rate limit at the same instant. The delay
            // is whole seconds so a scheduling run is reproducible.
            CommentSyncJob::dispatch((int) $account->getKey())
                ->delay($stagger ? $index * 15 : 0);
        }

        $this->info(sprintf(
            '%s comment syncs for %d account(s).',
            $inline ? 'Ran' : 'Queued',
            $accounts->count(),
        ));

        return self::SUCCESS;
    }

    private function syncOne(int $accountId): void
    {
        $result = CommentSyncJob::syncNow($accountId);

        if ($result === null) {
            $this->warn("Account {$accountId} was not found.");

            return;
        }

        if ($result->failed()) {
            $this->warn(sprintf('Account %d: %s', $accountId, (string) $result->failure));

            return;
        }

        $this->info(sprintf('Account %d: %d new, %d updated.', $accountId, $result->created, $result->updated));
    }
}
