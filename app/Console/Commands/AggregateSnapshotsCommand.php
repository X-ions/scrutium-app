<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Analytics\AggregateSnapshotsJob;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Services\Analytics\SnapshotAggregator;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuilds `analytics_snapshots` for a tenant, an account, or everything.
 *
 * Aggregation is idempotent, so this is safe to re-run at any time; the
 * scheduler runs it daily after the hourly syncs have landed. When the queue
 * connection is `sync` the work runs inline, because queuing to a driver that
 * executes in-process only adds a hop and hides the output.
 */
class AggregateSnapshotsCommand extends Command
{
    protected $signature = 'socialhub:analytics:aggregate
        {--tenant= : Aggregate a single tenant id}
        {--account= : Aggregate a single social account id (implies its tenant)}
        {--period=daily : One of hourly, daily, weekly, monthly}
        {--all-periods : Aggregate every supported period}
        {--days= : Only re-aggregate periods from the last N days}
        {--sync : Force the work to run inline}';

    protected $description = 'Roll analytics_metrics up into analytics_snapshots';

    public function handle(SnapshotAggregator $aggregator): int
    {
        $periods = $this->periods();

        [$from, $to] = $this->window();

        try {
            $written = $this->aggregate($aggregator, $periods, $from, $to);
        } catch (Throwable $e) {
            Log::error('SocialHub snapshot aggregation command failed.', ['error' => $e->getMessage()]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Wrote %d snapshot row(s) across %s.', $written, implode(', ', $periods)));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function periods(): array
    {
        if ($this->option('all-periods')) {
            return SnapshotAggregator::PERIODS;
        }

        $period = (string) $this->option('period');

        return [in_array($period, SnapshotAggregator::PERIODS, true) ? $period : SnapshotAggregator::PERIOD_DAILY];
    }

    /**
     * @param  list<string>  $periods
     */
    private function aggregate(SnapshotAggregator $aggregator, array $periods, ?DateTimeImmutable $from, ?DateTimeImmutable $to): int
    {
        $accountId = (int) $this->option('account');

        if ($accountId > 0) {
            $account = SocialAccount::withoutGlobalScopes()->with('tenant')->find($accountId);

            if ($account === null) {
                $this->error(sprintf('No social account with id %d.', $accountId));

                return 0;
            }

            if ($this->runsInline()) {
                return $aggregator->aggregateForAccount($account, $periods, $from, $to);
            }

            AggregateSnapshotsJob::dispatch(
                (int) $account->tenant_id,
                $accountId,
                $from?->format(DATE_ATOM),
                $to?->format(DATE_ATOM),
            );

            return 0;
        }

        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($query) => $query->whereKey((int) $this->option('tenant')))
            ->get();

        if ($this->runsInline()) {
            $written = 0;

            foreach ($tenants as $tenant) {
                $written += $aggregator->aggregateForTenant($tenant, $periods, $from, $to);
            }

            return $written;
        }

        foreach ($tenants as $tenant) {
            AggregateSnapshotsJob::dispatch((int) $tenant->id, null, $from?->format(DATE_ATOM), $to?->format(DATE_ATOM));
        }

        return 0;
    }

    private function runsInline(): bool
    {
        return (bool) $this->option('sync') || (string) config('queue.default', 'sync') === 'sync';
    }

    /**
     * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null}
     */
    private function window(): array
    {
        $days = (int) $this->option('days');

        if ($days <= 0) {
            return [null, null];
        }

        $to = new DateTimeImmutable;

        return [$to->modify(sprintf('-%d days', $days)), $to];
    }
}
