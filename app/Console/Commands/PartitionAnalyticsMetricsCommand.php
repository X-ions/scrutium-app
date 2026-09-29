<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Analytics\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Monthly partition maintenance for `analytics_metrics`.
 *
 * `analytics_metrics` is the one table in this schema large enough to be worth
 * partitioning, and the schema migration cannot emit `PARTITION BY` because
 * SQLite and PostgreSQL would reject it. This command is therefore the
 * MySQL-only half of the story and **no-ops with an explanation on every other
 * driver** — it never silently does nothing and never emits raw DDL that a
 * non-MySQL connection would choke on.
 *
 * Two jobs, both idempotent:
 *
 * - **extend** — ensure a `pYYYYMM` partition exists for this month and the
 *   next one, so an ingest at 00:01 on the first of the month cannot land in a
 *   table with no partition to accept it.
 * - **retain** — drop partitions older than the widest tenant retention
 *   (`tenants.analytics_retention_days`). The widest one is used on purpose:
 *   dropping to the narrowest would destroy data a longer-running tenant is
 *   still entitled to. A `--tenant` filter narrows the calculation when an
 *   operator wants a single tenant's boundary applied deliberately.
 */
class PartitionAnalyticsMetricsCommand extends Command
{
    protected $signature = 'socialhub:analytics:partitions
        {--dry-run : Report what would change without touching the schema}
        {--initialize : Add RANGE partitioning to the table if it has none}
        {--tenant= : Apply this tenant id\'s retention instead of the widest one}
        {--no-drop : Only ensure future partitions exist}';

    protected $description = 'Maintain the MySQL monthly partitions on analytics_metrics';

    public function handle(RetentionPolicy $retention): int
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn(sprintf(
                'analytics_metrics partitioning is MySQL-only; this connection is "%s", so there is nothing to do. '
                .'Partitioning is skipped there by design — the portable indexes created by the schema migration do the same job.',
                $driver,
            ));

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            if (! $this->isPartitioned()) {
                if (! $this->option('initialize')) {
                    $this->warn('analytics_metrics is not partitioned. Re-run with --initialize to add monthly RANGE partitions.');

                    return self::SUCCESS;
                }

                return $this->initializePartitioning($dryRun);
            }

            $this->extend($dryRun);
            $this->dropExpired($dryRun, $retention);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function isPartitioned(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS partitions FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            ['analytics_metrics'],
        );

        return (int) ($row->partitions ?? 0) > 0;
    }

    private function initializePartitioning(bool $dryRun): int
    {
        $definition = '';
        $index = 0;

        foreach ($this->monthRange() as $month => $next) {
            $definition .= sprintf(
                '%sPARTITION %s VALUES LESS THAN (TO_DAYS(\'%s\'))',
                $index === 0 ? '' : ', ',
                $this->partitionName($month),
                $next->format('Y-m-d'),
            );

            $index++;
        }

        $statement = sprintf(
            'ALTER TABLE analytics_metrics PARTITION BY RANGE (TO_DAYS(period_start)) (%s)',
            $definition,
        );

        if ($dryRun) {
            $this->line('Would run: '.$statement);

            return self::SUCCESS;
        }

        DB::statement($statement);

        $this->info('analytics_metrics is now partitioned by month.');

        return self::SUCCESS;
    }

    private function extend(bool $dryRun): void
    {
        $existing = $this->partitions();

        foreach ($this->monthRange() as $month => $next) {
            $name = $this->partitionName($month);

            if (isset($existing[$name])) {
                continue;
            }

            $statement = sprintf(
                "ALTER TABLE analytics_metrics ADD PARTITION (PARTITION %s VALUES LESS THAN (TO_DAYS('%s')))",
                $name,
                $next->format('Y-m-d'),
            );

            $this->apply($statement, $dryRun, sprintf('add partition %s', $name));
        }
    }

    private function dropExpired(bool $dryRun, RetentionPolicy $retention): void
    {
        if ($this->option('no-drop')) {
            return;
        }

        $days = $this->retentionDays($retention);
        $cutoff = CarbonImmutable::now()->modify(sprintf('-%d months', (int) max(1, round($days / 30))));

        foreach (array_keys($this->partitions()) as $name) {
            $month = $this->monthFromPartitionName($name);

            if ($month === null || $month >= $cutoff) {
                continue;
            }

            $this->apply(
                sprintf('ALTER TABLE analytics_metrics DROP PARTITION %s', $name),
                $dryRun,
                sprintf('drop partition %s (older than %d days of retention)', $name, $days),
            );
        }
    }

    private function retentionDays(RetentionPolicy $retention): int
    {
        $tenantId = (int) $this->option('tenant');

        if ($tenantId > 0) {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                $this->warn(sprintf('No tenant with id %d; falling back to the widest retention.', $tenantId));
            } else {
                return $retention->days($tenant);
            }
        }

        $days = RetentionPolicy::DEFAULT_DAYS;

        foreach (Tenant::query()->get() as $tenant) {
            $days = max($days, $retention->days($tenant));
        }

        return $days;
    }

    private function apply(string $statement, bool $dryRun, string $description): void
    {
        if ($dryRun) {
            $this->line(sprintf('Would %s.', $description));

            return;
        }

        DB::statement($statement);

        $this->info(sprintf('Partition maintenance: %s.', $description));
    }

    /**
     * This month and the next two, so a partition always exists ahead of the
     * data that will land in it.
     *
     * @return array<string, CarbonImmutable>
     */
    private function monthRange(): array
    {
        $months = [];
        $cursor = $this->monthStart(CarbonImmutable::now());

        for ($i = 0; $i < 3; $i++) {
            $next = $cursor->addMonthNoOverflow();

            $months[$cursor->format('Ym')] = $next;

            $cursor = $next;
        }

        return $months;
    }

    /**
     * @return array<string, string>
     */
    private function partitions(): array
    {
        $rows = DB::select(
            'SELECT PARTITION_NAME FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            ['analytics_metrics'],
        );

        $partitions = [];

        foreach ($rows as $row) {
            $name = (string) $row->PARTITION_NAME;

            if ($name !== 'p0') {
                $partitions[$name] = $name;
            }
        }

        return $partitions;
    }

    private function partitionName(CarbonImmutable $month): string
    {
        return 'p'.$month->format('Ym');
    }

    private function monthFromPartitionName(string $name): ?CarbonImmutable
    {
        if (preg_match('/^p(\d{6})$/', $name, $matches) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Ym', $matches[1], 'UTC');

        return $date === false ? null : $date;
    }

    private function monthStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfMonth()->startOfDay();
    }
}
