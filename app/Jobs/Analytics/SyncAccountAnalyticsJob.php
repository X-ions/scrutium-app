<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Enums\SocialAccountStatus;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenExpiredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Models\SocialAccount;
use App\Models\SocialHubNotification;
use App\Services\Analytics\AnalyticsIngestService;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\SocialProviderRegistry;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls analytics for one connected account and stores them.
 *
 * One account per job, dispatched per account by the scheduler: a provider
 * outage, a revoked token or a rate limit on one account can therefore never
 * affect another account's sync, because they never share a process, a
 * transaction or a retry budget.
 *
 * Failure handling per provider exception:
 *
 * - `UnsupportedCapabilityException` — the network has no analytics. The
 *   account is skipped silently; retrying would fail identically forever.
 * - `RateLimitException` — released for the provider's own `retryAfter`. The
 *   attempt is not consumed, so a throttled account keeps its place in the queue.
 * - `TokenExpiredException` / `TokenRevokedException` — the account is marked
 *   and the tenant is notified once. No retry: the token cannot come back on
 *   its own, and looping on it would hammer the provider.
 * - `ProviderApiException` with a 5xx status — released with a growing backoff.
 * - anything else — rethrown so the queue records it against this account only.
 */
class SyncAccountAnalyticsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public int $socialAccountId,
        public int $lookbackDays = 7,
    ) {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return 'socialhub:analytics-sync:'.$this->socialAccountId;
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter(900)];
    }

    public function handle(
        SocialProviderRegistry $registry,
        AnalyticsIngestService $ingest,
    ): void {
        $account = $this->account();

        if ($account === null) {
            return;
        }

        if (! $account->isActive()) {
            return;
        }

        TenantContext::set($account->tenant);

        try {
            $provider = $registry->get($account->provider->value);

            $query = AnalyticsQuery::forWindow($this->lookbackDays, 'day', $this->timezoneFor($account));

            $result = $ingest->ingestBatch($account, $provider->getAnalytics($account, $query), $query->to);
            $followers = $ingest->ingestAccountAnalytics($account, $provider->getFollowers($account), $query->to);
            $result = $result->merge($followers);

            $account->markConnected();

            if ($result->hasUnattributedPosts()) {
                $this->reportUnattributed($account, $result->unmatchedPostIds);
            }

            if ($result->unmappedKeys !== []) {
                Log::info('SocialHub analytics: provider keys with no canonical metric.', [
                    'social_account_id' => $account->getKey(),
                    'provider' => $account->provider->value,
                    'keys' => $result->unmappedKeys,
                ]);
            }

            AggregateSnapshotsJob::dispatch((int) $account->tenant_id, $account->getKey(), $query->from, $query->to);

            Log::info('SocialHub analytics synced.', [
                'social_account_id' => $account->getKey(),
                'written' => $result->written(),
                'unattributed' => count($result->unmatchedPostIds),
            ]);
        } catch (UnsupportedCapabilityException $e) {
            Log::info('SocialHub analytics skipped: the network does not offer analytics.', [
                'social_account_id' => $account->getKey(),
                'provider' => $account->provider->value,
                'capability' => $e->capability,
            ]);
        } catch (RateLimitException $e) {
            Log::notice('SocialHub analytics throttled; releasing the job.', [
                'social_account_id' => $account->getKey(),
                'provider' => $e->provider,
                'retry_after' => $e->retryAfter,
            ]);

            $this->release($e->retryAfter);
        } catch (TokenRevokedException $e) {
            $this->markUnusable($account, SocialAccountStatus::Revoked, $e->getMessage(), 'revoked');
        } catch (TokenExpiredException $e) {
            $this->markUnusable($account, SocialAccountStatus::Expired, $e->getMessage(), 'expired');
        } catch (ProviderNotConfiguredException $e) {
            Log::warning('SocialHub analytics skipped: the provider is not configured.', [
                'social_account_id' => $account->getKey(),
                'provider' => $account->provider->value,
            ]);
        } catch (ProviderApiException $e) {
            if ($e->status >= 500) {
                Log::warning('SocialHub analytics: the provider is failing; retrying.', [
                    'social_account_id' => $account->getKey(),
                    'provider' => $e->provider,
                    'status' => $e->status,
                ]);

                $this->release($this->backoff()[min($this->attempts(), 2)]);

                return;
            }

            $account->markStatus(SocialAccountStatus::Error, $e->getMessage());

            $this->notify($account, 'social_account.analytics_error', 'Analytics could not be synced', $e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SocialHub analytics sync failed.', [
            'social_account_id' => $this->socialAccountId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * The account is loaded without the tenant scope because a queued job has
     * no resolved tenant yet; the scope would otherwise hide the very row the
     * job was dispatched for. Its tenant is then pinned for the rest of the run
     * so every read inside is scoped exactly as it is in a web request.
     */
    private function account(): ?SocialAccount
    {
        try {
            return SocialAccount::withoutGlobalScopes()->find($this->socialAccountId);
        } catch (Throwable $e) {
            Log::error('SocialHub analytics: the social account could not be loaded.', [
                'social_account_id' => $this->socialAccountId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function markUnusable(SocialAccount $account, SocialAccountStatus $status, string $reason, string $reasonKey): void
    {
        $account->markStatus($status, $reason);

        $label = $account->provider?->label() ?? 'The network';

        $this->notify(
            $account,
            'social_account.token_'.$reasonKey,
            sprintf('%s needs to be reconnected', $label),
            $reason,
        );

        Log::warning('SocialHub analytics: the access token is no longer usable.', [
            'social_account_id' => $account->getKey(),
            'provider' => $account->provider->value,
            'status' => $status->value,
        ]);
    }

    /**
     * @param  list<string>  $postIds
     */
    private function reportUnattributed(SocialAccount $account, array $postIds): void
    {
        $this->notify(
            $account,
            'analytics.unattributed_posts',
            'Some post metrics could not be matched to a post',
            sprintf(
                '%d post(s) published outside SocialHub reported metrics. They are stored against the account, not as orphans.',
                count($postIds),
            ),
            ['provider_post_ids' => array_slice($postIds, 0, 20)],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notify(SocialAccount $account, string $type, string $title, string $message, array $data = []): void
    {
        SocialHubNotification::create([
            'tenant_id' => $account->tenant_id,
            'user_id' => null,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => ['social_account_id' => $account->getKey(), 'provider' => $account->provider->value] + $data,
            'priority' => 'high',
        ]);
    }

    private function timezoneFor(SocialAccount $account): string
    {
        $tenant = $account->tenant;
        $timezone = $tenant?->getAttribute('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
