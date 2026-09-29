<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Services\Analytics\SnapshotAggregator;
use App\Support\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rolls freshly ingested metrics up into `analytics_snapshots`.
 *
 * Dispatched per account after ingestion. Re-running is safe: the aggregator
 * matches on the full unique identity and updates in place, so a duplicated
 * dispatch, a retried job or a manual re-run all converge on the same rows.
 */
class AggregateSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public int $tenantId,
        public ?int $socialAccountId = null,
        public DateTimeInterface|string|null $from = null,
        public DateTimeInterface|string|null $to = null,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(SnapshotAggregator $aggregator): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $from = $this->date($this->from);
        $to = $this->date($this->to);

        if ($this->socialAccountId !== null) {
            $account = SocialAccount::withoutGlobalScopes()->find($this->socialAccountId);

            if ($account === null) {
                return;
            }

            TenantContext::set($tenant);

            try {
                $written = $aggregator->aggregateForAccount($account, SnapshotAggregator::PERIODS, $from, $to);
            } finally {
                TenantContext::forget();
            }

            Log::info('SocialHub snapshots aggregated for an account.', [
                'social_account_id' => $this->socialAccountId,
                'rows' => $written,
            ]);

            return;
        }

        $written = $aggregator->aggregateForTenant($tenant, SnapshotAggregator::PERIODS, $from, $to);

        Log::info('SocialHub snapshots aggregated for a tenant.', [
            'tenant_id' => $this->tenantId,
            'rows' => $written,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SocialHub snapshot aggregation failed.', [
            'tenant_id' => $this->tenantId,
            'social_account_id' => $this->socialAccountId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function date(DateTimeInterface|string|null $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeImmutable
            ? $value
            : DateTimeImmutable::createFromInterface($value instanceof DateTimeInterface
                ? $value
                : new DateTimeImmutable($value));
    }
}
