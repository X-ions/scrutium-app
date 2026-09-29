<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Enums\MediaType;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Capabilities\PlatformCapabilities;
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
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A second-network provider for the publishing tests.
 *
 * Only Facebook ships a real implementation, but the per-variant isolation the
 * publishing engine promises cannot be proven with one network. This stand-in
 * publishes over the same `ProviderHttpClient`, so `Http::fake()` controls it
 * exactly as it controls the real thing, and it reports the platform's verified
 * capability matrix rather than an invented one.
 */
class FakeNetworkProvider implements SocialProviderInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    public function __construct(
        private readonly string $key = 'instagram',
        private readonly string $host = 'graph.instagram.test',
    ) {}

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        $this->calls[] = [
            'operation' => 'publishPost',
            'account_id' => $account->getKey(),
            'variant_id' => $variant->getKey(),
            'caption' => $variant->caption,
        ];

        $response = \Illuminate\Support\Facades\Http::post(sprintf(
            'https://%s/v1/%s/media',
            $this->host,
            (string) $account->provider_account_id,
        ), [
            'caption' => (string) $variant->caption,
            'media_count' => count((array) $variant->media_ids),
        ]);

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);

            throw new \App\Exceptions\Social\RateLimitException(
                $this->key,
                $retryAfter,
                \App\Services\Social\Data\UserFacingError::make(
                    'rate_limited',
                    sprintf('%s is asking us to slow down. The post will be retried automatically.', ucfirst($this->key)),
                    sprintf('Fake provider "%s" returned HTTP 429.', $this->key),
                    true,
                ),
                null,
                ['retry_after' => $retryAfter],
            );
        }

        if (! $response->successful()) {
            throw new \App\Exceptions\Social\ProviderApiException(
                $this->key,
                $response->status(),
                null,
                $response->status() >= 500
                    ? \App\Services\Social\Data\UserFacingError::make(
                        'provider_unavailable',
                        sprintf('%s is having trouble right now. The post will be retried.', ucfirst($this->key)),
                        sprintf('Fake provider "%s" returned HTTP %d.', $this->key, $response->status()),
                        true,
                    )
                    : null,
            );
        }

        $id = (string) ($response->json('id') ?? 'fake-'.$variant->getKey());

        return new ProviderPost(
            provider: $this->key,
            providerPostId: $id,
            permalink: sprintf('https://%s/p/%s', $this->host, $id),
            text: $variant->caption,
            contentType: 'image',
            publishedAt: new DateTimeImmutable,
            raw: ['id' => $id],
        );
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        return PlatformCapabilities::for($this->key);
    }

    public function createPost(SocialAccount $account, array $payload): ProviderPost
    {
        throw new UnsupportedCapabilityException('createPost', $this->key);
    }

    public function authenticate(AuthRequest $request): AuthSession
    {
        throw new UnsupportedCapabilityException('authenticate', $this->key);
    }

    public function refreshToken(SocialAccount $account): TokenSet
    {
        throw new UnsupportedCapabilityException('refreshToken', $this->key);
    }

    public function getAccount(SocialAccount $account): AccountProfile
    {
        throw new UnsupportedCapabilityException('getAccount', $this->key);
    }

    public function getPages(SocialAccount $account): array
    {
        return [];
    }

    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        return 'fake-media-'.$media->getKey();
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        return true;
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        return new ProviderPost(provider: $this->key, providerPostId: $providerPostId);
    }

    public function getComments(SocialAccount $account, ?string $providerPostId = null): array
    {
        return [];
    }

    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        throw new UnsupportedCapabilityException('commentReplies', $this->key);
    }

    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        throw new UnsupportedCapabilityException('analytics', $this->key);
    }

    public function getFollowers(SocialAccount $account): FollowerStats
    {
        throw new UnsupportedCapabilityException('followers', $this->key);
    }

    public function disconnect(SocialAccount $account): void {}

    public function verifyWebhook(VerifiedWebhookRequest $request): bool
    {
        return true;
    }

    public function normalizeWebhook(array $payload): array
    {
        return $payload;
    }

    public function mediaTypeFor(PostVariant $variant): MediaType
    {
        return MediaType::Image;
    }

    public function lastPublishedAt(): ?DateTimeInterface
    {
        return new DateTimeImmutable;
    }
}
