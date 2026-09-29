<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\SocialAccount;
use App\Models\Tenant;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * How far back a tenant keeps raw analytics.
 *
 * `tenants.analytics_retention_days` is the contract. A period older than the
 * cutoff is not written: dropping a reading is the honest response to "we are
 * no longer allowed to keep this", and it is the same boundary the MySQL
 * partition maintenance drops partitions on, so the two never disagree.
 */
final class RetentionPolicy
{
    public const DEFAULT_DAYS = 30;

    public function days(?Tenant $tenant): int
    {
        $configured = $tenant?->getAttribute('analytics_retention_days');

        if (! is_numeric($configured)) {
            return self::DEFAULT_DAYS;
        }

        return max(0, (int) $configured);
    }

    public function daysFor(SocialAccount $account): int
    {
        return $this->days($account->tenant);
    }

    public function cutoff(SocialAccount $account, ?DateTimeInterface $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable;

        return DateTimeImmutable::createFromInterface($now)
            ->modify(sprintf('-%d days', $this->daysFor($account)));
    }

    public function admits(SocialAccount $account, ?DateTimeInterface $periodStart, ?DateTimeInterface $cutoff = null): bool
    {
        if ($periodStart === null) {
            return true;
        }

        $cutoff ??= $this->cutoff($account);

        return DateTimeImmutable::createFromInterface($periodStart) >= $cutoff;
    }
}
