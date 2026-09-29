<?php

declare(strict_types=1);

namespace Tests\Support\Engagement;

use App\Models\Comment;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\AuthSession;
use App\Services\Social\Data\FollowerStats;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\Data\ProviderPost;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\SocialProviderRegistry;
use Throwable;

/**
 * `$k stand-in.
 *
 * SocialProviderRegistry instantiates by class name with no constructor
 * arguments, so the platform a fake reports has to come from the class itself.
 * Naming it per platform is also what keeps getSupportedFeatures() honest:
 * the "cannot reply" test needs Pinterest's real no-comment-API matrix, not a
 * blanket fake that supports everything.
 */
class FakeEngagementProvider implements \App\Services\Social\Contracts\SocialProviderInterface
{
    public const SECRET = 'engagement-test-secret';

    /** Per social account id: comments to return, or an exception to throw. */
    public static array $comments = [];

    /** Per social account id: an exception to throw from replyToComment(). */
    public static array $replyFailures = [];

    /** Per social account id: an exception to throw from refreshToken(). */
    public static array $refreshFailures = [];

    /** Comment ids handed to replyToComment(), in call order. */
    public static array $repliedTo = [];

    /** Reply bodies handed to replyToComment(), in call order. */
    public static array $replyBodies = [];

    public static int $commentCalls = 0;

    public static int $refreshCalls = 0;

    public function __construct(protected string $platform = 'facebook') {}

    public static function reset(): void
    {
        self::$comments = [];
        self::$replyFailures = [];
        self::$refreshFailures = [];
        self::$repliedTo = [];
        self::$replyBodies = [];
        self::$commentCalls = 0;
        self::$refreshCalls = 0;
    }

    /**
     * @param  array<\App\Services\Social\Data\ProviderComment>|Throwable  $outcome
     */
    public static function commentsFor(SocialAccount $account, array|Throwable $outcome): void
    {
        self::$comments[(int) $account->getKey()] = $outcome;
    }

    public static function failRepliesFor(SocialAccount $account, Throwable $outcome): void
    {
        self::$replyFailures[(int) $account->getKey()] = $outcome;
    }

    /**
     * @param  Throwable|TokenSet  $outcome  A failure to throw, or the token
     *                                       set a provider that does not
     *                                       rotate refresh tokens returns.
     */
    public static function refreshFor(SocialAccount $account, Throwable|TokenSet $outcome): void
    {
        self::$refreshFailures[(int) $account->getKey()] = $outcome;
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        return \App\Services\Social\Capabilities\PlatformCapabilities::for($this->platform);
    }

    public function getComments(SocialAccount $account, ?string $providerPostId = null): array
    {
        self::$commentCalls++;

        $outcome = self::$comments[(int) $account->getKey()] ?? [];

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return is_array($outcome) ? $outcome : [];
    }

    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        $failure = self::$replyFailures[(int) $account->getKey()] ?? null;

        if ($failure !== null) {
            throw $failure;
        }

        self::$repliedTo[] = (int) $account->getKey();
        self::$replyBodies[] = $body;
    }

    public function refreshToken(SocialAccount $account): TokenSet
    {
        self::$refreshCalls++;

        $failure = self::$refreshFailures[(int) $account->getKey()] ?? null;

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        if ($failure instanceof TokenSet) {
            return $failure;
        }

        return new TokenSet(
            accessToken: 'refreshed-access-token',
            refreshToken: 'refreshed-refresh-token',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );
    }

    public function verifyWebhook(VerifiedWebhookRequest $request): bool
    {
        $signature = $request->header('x-hub-signature-256');

        if ($signature === null) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->rawBody, self::SECRET);

        return hash_equals($expected, $signature);
    }

    public function normalizeWebhook(array $payload): array
    {
        return $payload;
    }

    public function authenticate(AuthRequest $request): AuthSession
    {
        throw new \LogicException('The engagement fake does not run an OAuth handshake.');
    }

    public function getAccount(SocialAccount $account): \App\Services\Social\Data\AccountProfile
    {
        throw new \LogicException('The engagement fake does not resolve accounts.');
    }

    public function getPages(SocialAccount $account): array
    {
        return [];
    }

    public function createPost(SocialAccount $account, array $payload): ProviderPost
    {
        throw new \LogicException('The engagement fake does not publish.');
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        throw new \LogicException('The engagement fake does not publish.');
    }

    public function uploadMedia(SocialAccount $account, \App\Models\MediaAsset $media): string
    {
        throw new \LogicException('The engagement fake does not upload media.');
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        return true;
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        throw new \LogicException('The engagement fake does not read posts.');
    }

    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        return new AnalyticsBatch([], [], [], null);
    }

    public function getFollowers(SocialAccount $account): FollowerStats
    {
        return new FollowerStats(0, 0, null, []);
    }

    public function disconnect(SocialAccount $account): void
    {
        //
    }
}
