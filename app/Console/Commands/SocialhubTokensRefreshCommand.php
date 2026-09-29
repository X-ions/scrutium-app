<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engagement\TokenRefreshService;
use Illuminate\Console\Command;

class SocialhubTokensRefreshCommand extends Command
{
    protected $signature = 'socialhub:tokens:refresh
        {--hours=24 : Refresh tokens expiring within this window}
        {--warn-only : Only warn about expiring tokens, do not refresh them}';

    protected $description = 'Refresh social account access tokens that are about to expire';

    public function handle(TokenRefreshService $tokens): int
    {
        $hours = (int) $this->option('hours');

        $tokens->warnExpiring($hours);

        if ($this->option('warn-only')) {
            $this->info('Warn-only run: no tokens were refreshed.');

            return self::SUCCESS;
        }

        $summary = $tokens->refreshExpiring($hours);

        if ($summary['lock_held']) {
            $this->warn('Another token refresh sweep is already running.');

            return self::SUCCESS;
        }

        $this->table(
            ['outcome', 'accounts'],
            collect($summary)
                ->except('lock_held')
                ->map(fn ($count, $outcome) => [$outcome, $count])
                ->values()
                ->all(),
        );

        if ($summary['revoked'] > 0 || $summary['expired'] > 0) {
            $this->warn(sprintf(
                '%d account(s) need to be reconnected. See the Social accounts page.',
                $summary['revoked'] + $summary['expired'],
            ));
        }

        return self::SUCCESS;
    }
}
