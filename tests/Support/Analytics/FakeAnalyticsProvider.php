<?php

declare(strict_types=1);

namespace Tests\Support\Analytics;

use App\Exceptions\Social\SocialProviderException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Data\AccountProfile;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\AuthSession;
use App\Services\Social\Data\FollowerStats;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\Data\ProviderPost;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\Data\VerifiedWebhookRequest;

/**
 * A provider whose analytics responses the test dictates.
 *
 * Each account id can be given its own {@see SocialProviderException} to throw
 * or its own batch to return, which is how the per-account failure isolation is
 * tested: two accounts on the same run, one throttled, one succeeding.
 */
class FakeAnalyticsProvider implements SocialProviderInterface
{
    /**
     * Per social account id: the batch to return, or the exception to throw.
     *
     * @var array<int, AnalyticsBatch|SocialProviderException>
     */
    public static array $batches = [];

    public static int $calls = 0;

    /**
     * @var list<int>
     */
    public static array $calledAccounts = [];

    public static function reset(): void
    {
        self::$batches = [];
        self::$calls = 0;
        self::$calledAccounts = [];
    }

    public static function for(SocialAccount $account, AnalyticsBatch|SocialProviderException $outcome): void
    {
        self::$batches[(int) $account->getKey()] = $outcome;
    }

    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        self::$calls++;
        self::$calledAccounts[] = (int) $account->getKey();

        return $this->outcomeFor($account, $query);
    }

    public function getFollowers(SocialAccount $account): FollowerStats
    {
        $outcome = self::$batches[(int) $account->getKey()] ?? null;

        if ($outcome instanceof SocialProviderException) {
            throw $outcome;
        }

        return new FollowerStats(
            provider: $account->provider->value,
            providerAccountId: (string) $account->provider_account_id,
            total: 0,
        );
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        return new ProviderCapabilities;
    }

    /**
     * A batch the account did not configure, scoped to the requested window.
     */
    private function outcomeFor(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        $outcome = self::$batches[(int) $account->getKey()] ?? null;

        if ($outcome instanceof SocialProviderException) {
            throw $outcome;
        }

        if ($outcome instanceof AnalyticsBatch) {
            return $outcome;
        }

        return new AnalyticsBatch(
            provider: $account->provider->value,
            periodStart: $query->from,
            periodEnd: $query->to,
        );
    }

    public function authenticate(AuthRequest $request): AuthSession
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function refreshToken(SocialAccount $account): TokenSet
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function getAccount(SocialAccount $account): AccountProfile
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function getPages(SocialAccount $account): array
    {
        return [];
    }

    public function createPost(SocialAccount $account, array $payload): ProviderPost
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        return true;
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function getComments(SocialAccount $account, ?string $providerPostId = null): array
    {
        return [];
    }

    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        throw new \LogicException('Not used by the analytics engine.');
    }

    public function disconnect(SocialAccount $account): void {}

    public function verifyWebhook(VerifiedWebhookRequest $request): bool
    {
        return true;
    }

    public function normalizeWebhook(array $payload): array
    {
        return [];
    }
}
