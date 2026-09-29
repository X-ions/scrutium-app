<?php

declare(strict_types=1);

namespace App\Services\Social\Contracts;

use App\Exceptions\Social\AuthenticationException;
use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\RateLimitException;
use App\Exceptions\Social\TokenExpiredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Exceptions\Social\WebhookSignatureException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
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
 * The only surface the publishing, composer, and analytics engines know.
 *
 * Implementations must throw {@see UnsupportedCapabilityException} when a
 * platform cannot do something — never a fabricated success response.
 */
interface SocialProviderInterface
{
    /**
     * Drive the OAuth handshake. On the "begin" leg the returned
     * {@see AuthSession} carries the redirect URL; on the callback leg it
     * carries the exchanged {@see TokenSet}.
     *
     * @throws AuthenticationException
     * @throws ProviderNotConfiguredException
     * @throws ProviderApiException
     */
    public function authenticate(AuthRequest $request): AuthSession;

    /**
     * @throws TokenRevokedException
     * @throws AuthenticationException
     */
    public function refreshToken(SocialAccount $account): TokenSet;

    /**
     * @throws TokenExpiredException
     * @throws TokenRevokedException
     * @throws ProviderApiException
     */
    public function getAccount(SocialAccount $account): AccountProfile;

    /**
     * Pages, channels, or profiles the connected identity may publish to.
     *
     * @return list<AccountProfile>
     *
     * @throws ProviderApiException
     */
    public function getPages(SocialAccount $account): array;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UnsupportedCapabilityException
     * @throws RateLimitException
     * @throws ProviderApiException
     */
    public function createPost(SocialAccount $account, array $payload): ProviderPost;

    /**
     * @throws UnsupportedCapabilityException
     * @throws RateLimitException
     * @throws ProviderApiException
     */
    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost;

    /**
     * Upload media and return the provider-side asset identifier.
     *
     * @throws UnsupportedCapabilityException
     * @throws ProviderApiException
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string;

    /**
     * @throws UnsupportedCapabilityException
     * @throws ProviderApiException
     */
    public function deletePost(SocialAccount $account, string $providerPostId): bool;

    /**
     * @throws ProviderApiException
     */
    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost;

    /**
     * @return list<ProviderComment>
     *
     * @throws ProviderApiException
     */
    public function getComments(SocialAccount $account, ?string $providerPostId = null): array;

    /**
     * @throws UnsupportedCapabilityException
     * @throws ProviderApiException
     */
    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void;

    /**
     * @throws UnsupportedCapabilityException
     * @throws ProviderApiException
     */
    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch;

    /**
     * @throws UnsupportedCapabilityException
     * @throws ProviderApiException
     */
    public function getFollowers(SocialAccount $account): FollowerStats;

    public function getSupportedFeatures(): ProviderCapabilities;

    /**
     * Best-effort platform-side cleanup. Local token deletion is the caller's
     * job, so a provider failure here must not block disconnecting.
     */
    public function disconnect(SocialAccount $account): void;

    /**
     * @throws WebhookSignatureException
     */
    public function verifyWebhook(VerifiedWebhookRequest $request): bool;

    /**
     * Flatten a provider-specific webhook body into the internal event shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeWebhook(array $payload): array;
}
