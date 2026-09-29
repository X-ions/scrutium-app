<?php

declare(strict_types=1);

namespace App\Jobs\Engagement;

use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Services\Engagement\CommentSyncService;
use App\Services\Publishing\StructuredLog;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Syncs one account's comments.
 *
 * Scoped to a single account so a rate limit or outage on one platform cannot
 * stall the others. The result is recorded rather than thrown, so a provider
 * that keeps refusing produces one log line per pass rather than a retry storm.
 */
final class CommentSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly int $socialAccountId,
        public readonly ?int $postVariantId = null,
        public readonly ?int $maxComments = null,
    ) {}

    public function handle(CommentSyncService $sync): void
    {
        // A queued job has no resolved tenant, so the account is loaded
        // unscoped and its tenant pinned immediately after.
        $account = SocialAccount::query()->withoutGlobalScopes()->find($this->socialAccountId);

        if ($account === null) {
            return;
        }

        TenantContext::set(Tenant::query()->find($account->tenant_id));

        $result = $sync->syncAccount($account, $this->maxComments);

        if ($result->failed()) {
            Log::info('socialhub.comments.sync_failed', [
                'account_id' => $account->id,
                'provider' => $account->provider?->value,
                'failure_code' => $result->failureCode,
            ]);

            return;
        }

        StructuredLog::write('comments.synced', [
            'account_id' => $account->id,
            'provider' => $account->provider?->value,
            'created' => $result->created,
            'updated' => $result->updated,
            'unchanged' => $result->unchanged,
            'skipped' => $result->skipped,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('socialhub.comments.job_failed', [
            'account_id' => $this->socialAccountId,
            'exception' => $exception::class,
        ]);
    }

    /**
     * Fan one job out per connected account.
     */
    public static function dispatchForAll(?int $variantId = null): void
    {
        foreach (SocialAccount::query()->connected()->pluck('id') as $accountId) {
            self::dispatch((int) $accountId, $variantId);
        }
    }

    /**
     * Convenience for a controller that wants the result synchronously.
     */
    public static function syncNow(int $accountId, ?int $maxComments = null): ?\App\Services\Engagement\CommentSyncResult
    {
        $account = SocialAccount::query()->withoutGlobalScopes()->find($accountId);

        if ($account === null) {
            return null;
        }

        TenantContext::set(Tenant::query()->find($account->tenant_id));

        return app(CommentSyncService::class)->syncAccount($account, $maxComments);
    }
}
