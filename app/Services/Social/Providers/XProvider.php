<?php

declare(strict_types=1);

namespace App\Services\Social\Providers;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Config\XConfig;
use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Data\AccountProfile;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\AnalyticsQuery;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\AuthSession;
use App\Services\Social\Data\FollowerStats;
use App\Services\Social\Data\MetricSample;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\Data\ProviderPost;
use App\Services\Social\Data\PublishPayload;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\OAuth\UsesOAuth2AuthorizationCode;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\Providers\Concerns\GuardsProviderOperations;
use App\Services\Social\Providers\Concerns\HasUnsignedWebhooks;
use App\Services\Social\Providers\Concerns\MapsProviderErrors;
use App\Services\Social\Providers\Concerns\PaginatesCursor;
use App\Services\Social\Providers\Concerns\PublishesFromPostVariant;
use App\Services\Social\Providers\Concerns\ReadsConnectedAccount;
use App\Services\Social\Providers\Concerns\RespectsRateLimitHeaders;
use App\Services\Social\Support\AccountTokenResolver;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * X API v2.
 *
 * Media is uploaded through the v2 chunked protocol — `initialize`, one
 * `append` per chunk, then `finalize` — and the resulting `media_id` is
 * attached to `POST /2/tweets`. Video and GIF need server-side processing after
 * finalize, so the returned `processing_info.check_after_secs` is honoured
 * rather than polling on a fixed schedule.
 *
 * X meters per endpoint and the costs are asymmetric, so the throttling headers
 * that come back name the bucket. The access token rotates on refresh, which is
 * why `refreshToken()` returns the pair and the caller must persist both.
 */
class XProvider implements SocialProviderInterface
{
    use GuardsProviderOperations;
    use HasUnsignedWebhooks;
    use MapsProviderErrors;
    use PaginatesCursor;
    use PublishesFromPostVariant;
    use ReadsConnectedAccount;
    use RespectsRateLimitHeaders;
    use UsesOAuth2AuthorizationCode;

    public const KEY = 'x';

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly AccountTokenResolver $tokens = new AccountTokenResolver,
    ) {}

    public function providerKey(): string
    {
        return self::KEY;
    }

    public function platformName(): string
    {
        return 'X';
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        $overrides = (array) config('socialhub.providers.x.capabilities_override', []);

        return PlatformCapabilities::x()->withOverrides($overrides);
    }

    // ---------------------------------------------------------------- OAuth

    public function authenticate(AuthRequest $request): AuthSession
    {
        $request = $request->withProvider(self::KEY);

        if ($request->state === null && $request->code === null) {
            $this->assertConfigured();
        }

        if ($request->code === null) {
            return $this->beginSession(
                $request,
                $this->oauthAuthorizeUrl($request->withState($request->state ?? $this->newState())),
            );
        }

        $tokens = $this->tokenSetFrom($this->exchangeAuthorizationCode($request, $this->oauthHttp()));

        return new AuthSession(
            provider: self::KEY,
            authorizationUrl: '',
            state: $request->state ?? '',
            redirectUri: $request->redirectUri,
            scopes: $tokens->scopes,
            tokens: $tokens,
        );
    }

    /**
     * X rotates the refresh token: every refresh returns a new one and
     * immediately invalidates the old. The caller has to persist both or the
     * connection dies on the following refresh.
     */
    public function refreshToken(SocialAccount $account): TokenSet
    {
        $this->assertConfigured();

        $stored = $this->tokens->resolve($account, self::KEY);

        if ($stored->refreshToken === null || $stored->refreshToken === '') {
            throw new TokenRevokedException(self::KEY, UserFacingError::make(
                'refresh_token_missing',
                'This X connection can no longer be refreshed. Reconnect the account to continue publishing.',
                'No refresh token was stored, so the offline.access grant is missing or was never persisted.',
                false,
                'Reconnect the account from the Social Accounts page and approve the offline.access scope.',
            ));
        }

        $refreshed = $this->refreshAccessToken($stored->refreshToken, $this->oauthHttp());

        // The provider omits the refresh token only if it did not rotate; if it
        // did rotate the new value is already on the returned TokenSet.
        return $refreshed->refreshToken === null
            ? new TokenSet(
                accessToken: $refreshed->accessToken,
                refreshToken: $stored->refreshToken,
                tokenType: $refreshed->tokenType,
                expiresIn: $refreshed->expiresIn,
                expiresAt: $refreshed->expiresAt,
                scopes: $refreshed->scopes,
                metadata: $refreshed->metadata,
            )
            : $refreshed;
    }

    // -------------------------------------------------------------- Account

    public function getAccount(SocialAccount $account): AccountProfile
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(XConfig::API_BASE.'/2/users/me', [
            'user.fields' => 'id,name,username,profile_image_url,public_metrics,created_at,verified',
        ]);

        $this->assertSuccessful($response, 'getAccount');

        return $this->profileFrom($this->dataFrom($response), $account);
    }

    /**
     * X has no sub-accounts, so the authenticated user is the only target.
     *
     * @return list<AccountProfile>
     */
    public function getPages(SocialAccount $account): array
    {
        return [$this->getAccount($account)];
    }

    public function getFollowers(SocialAccount $account): FollowerStats
    {
        $this->assertUnsupported('followers');
        $this->assertConfigured();

        $userId = (string) ($this->userId($account) ?? $this->accountIdOf($account));

        if ($userId === '') {
            $userId = (string) ((array) $this->getAccount($account)->raw)['id'];
        }

        $response = $this->api($account)->get(sprintf('%s/2/users/%s/followers/count', XConfig::API_BASE, $userId));

        $this->assertSuccessful($response, 'getFollowers');

        $data = $this->dataFrom($response);
        $total = (int) ($data['followers_count'] ?? 0);

        return new FollowerStats(
            provider: self::KEY,
            providerAccountId: $userId,
            total: $total,
            breakdown: ['followers' => $total],
            asOf: new DateTimeImmutable,
            raw: $data,
        );
    }

    // ------------------------------------------------------------ Publishing

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPost(SocialAccount $account, array $payload): ProviderPost
    {
        $this->assertUnsupported('publishing');
        $this->assertConfigured();

        $publish = $this->payloadFromRequest($payload);

        if ($publish->isScheduled()) {
            // X has no scheduled publish endpoint.
            $this->assertUnsupported('scheduling');
        }

        $type = $publish->contentType();
        $mediaIds = $this->mediaIdsFor($publish, $type);

        $this->assertUnsupported($this->capabilityFor($type));

        if (($publish->options['reply_to_post_id'] ?? null) !== null) {
            $this->assertUnsupported('commentReplies');
        }

        $body = ['text' => $publish->text];

        if ($mediaIds !== []) {
            $body['media'] = ['media_ids' => $mediaIds];
        }

        if (($publish->options['reply_to_post_id'] ?? null) !== null) {
            $body['reply'] = ['in_reply_to_tweet_id' => (string) $publish->options['reply_to_post_id']];
        }

        if (($publish->options['quote_post_id'] ?? null) !== null) {
            $this->assertUnsupported('linkPosts');
            $body['quote_tweet_id'] = (string) $publish->options['quote_post_id'];
        }

        $response = $this->api($account)->post(XConfig::API_BASE.XConfig::POSTS, $body);

        $this->assertSuccessful($response, 'createPost');

        $data = $this->dataFrom($response);
        $postId = (string) ($data['id'] ?? '');

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: $postId,
            permalink: $postId === '' ? null : sprintf('https://x.com/i/web/status/%s', $postId),
            text: isset($data['text']) ? (string) $data['text'] : $publish->text,
            contentType: $type,
            attachedMediaIds: $mediaIds,
            publishedAt: new DateTimeImmutable,
            raw: $data,
        );
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        return $this->createPost($account, ['payload' => $this->payloadFromVariant($variant)]);
    }

    /**
     * Runs the v2 chunked upload and returns the media id to attach.
     *
     * Image uploads finalise immediately. Video and GIF go into a
     * `processing` state whose `check_after_secs` is honoured before the media
     * can be attached, so a Post is never sent with a media_id X will reject.
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        $this->assertConfigured();

        $mimeType = (string) ($this->attribute($media, 'mime_type') ?: 'image/jpeg');
        $category = $this->mediaCategoryFor($mimeType);

        $this->assertUnsupported(match ($category) {
            'tweet_video' => 'videoPublishing',
            'tweet_gif' => 'imagePublishing',
            default => 'imagePublishing',
        });

        $this->assertWithinByteLimit(
            (int) ($this->attribute($media, 'file_size') ?? 0),
            $this->maxBytesFor($category),
            sprintf('Upload a smaller file; X caps %s uploads well below this size.', $category),
        );

        $init = $this->api($account)->post(XConfig::API_BASE.XConfig::MEDIA_INITIALIZE, [
            'media_category' => $category,
            'media_type' => $mimeType,
            'total_bytes' => (int) ($this->attribute($media, 'file_size') ?? 0),
            'shared' => true,
        ]);

        $this->assertSuccessful($init, 'uploadMedia.initialize');

        $mediaId = (string) ($this->dataFrom($init)['id'] ?? '');

        if ($mediaId === '') {
            throw $this->buildApiException($init, UserFacingError::make(
                'media_id_missing',
                'X did not return a media id for this upload.',
                'The media upload initialize response carried no data.id.',
                true,
                'Retry the upload; if it keeps failing, check that media.write is granted for this app.',
            ));
        }

        $this->appendChunks($account, $media, $mediaId);
        $final = $this->finalizeUpload($account, $mediaId);
        $this->awaitProcessing($account, $final, $mediaId);

        return (string) ($this->dataFrom($final)['media_id_string'] ?? $this->dataFrom($final)['id'] ?? $mediaId);
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        $this->assertUnsupported('deletePost');
        $this->assertConfigured();

        $response = $this->api($account)->delete(sprintf('%s/2/tweets/%s', XConfig::API_BASE, $providerPostId));

        $this->assertSuccessful($response, 'deletePost');

        return $response->successful();
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(sprintf('%s/2/tweets/%s', XConfig::API_BASE, $providerPostId), [
            'tweet.fields' => 'created_at,public_metrics,entities,attachments,conversation_id,in_reply_to_user_id',
            'expansions' => 'attachments.media_keys',
            'media.fields' => 'type,url,preview_image_url,duration_ms,width,height',
        ]);

        $this->assertSuccessful($response, 'getPost');

        $data = $this->dataFrom($response);

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: (string) ($data['id'] ?? $providerPostId),
            permalink: sprintf('https://x.com/i/web/status/%s', $data['id'] ?? $providerPostId),
            text: isset($data['text']) ? (string) $data['text'] : null,
            contentType: $this->contentTypeFrom($data),
            publishedAt: $this->readDate($data, 'created_at'),
            createdAt: $this->readDate($data, 'created_at'),
            raw: $data,
        );
    }

    // ------------------------------------------------------------- Comments

    /**
     * X's v2 API has no read endpoint for replies to a Post, so the honest
     * answer is that the data is not available rather than an empty list that
     * looks like "no comments yet".
     *
     * @return list<ProviderComment>
     */
    public function getComments(SocialAccount $account, ?string $providerPostId = null): array
    {
        $this->assertUnsupported('comments');
    }

    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        $this->assertUnsupported('commentReplies');
        $this->assertConfigured();

        $response = $this->api($account)->post(XConfig::API_BASE.XConfig::POSTS, [
            'text' => $body,
            'reply' => ['in_reply_to_tweet_id' => $comment->providerPostId],
        ]);

        $this->assertSuccessful($response, 'replyToComment');
    }

    // ------------------------------------------------------------- Insights

    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        $this->assertUnsupported('analytics');
        $this->assertConfigured();

        $postMetrics = [];

        foreach ($query->postIds as $postId) {
            $samples = $this->publicMetrics($account, (string) $postId, $query->metrics);

            if ($samples !== []) {
                $postMetrics[(string) $postId] = $samples;
            }
        }

        return new AnalyticsBatch(
            provider: self::KEY,
            periodStart: $query->from,
            periodEnd: $query->to,
            accountMetrics: $this->userMetrics($account, $query->metrics),
            postMetrics: $postMetrics,
            granularity: $query->granularity,
        );
    }

    // ------------------------------------------------------------ Lifecycle

    public function disconnect(SocialAccount $account): void
    {
        $this->assertConfigured();

        try {
            $this->oauthHttp()->postForm(XConfig::REVOKE_URL, [
                'token' => $this->tokens->resolve($account, self::KEY)->refreshToken,
            ]);
        } catch (Throwable $e) {
            logger()->warning('SocialHub provider disconnect call failed; continuing with the local disconnect.', [
                'provider' => self::KEY,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ---------------------------------------------------------------- Errors

    /**
     * @return array<string, UserFacingError>
     */
    protected function errorMap(): array
    {
        return XConfig::errorMap();
    }

    /**
     * X returns a problem document with a numeric `code`, and the OAuth error
     * body uses a `detail` string that names the condition.
     */
    protected function extractErrorCode(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $detail = $body['detail'] ?? $body['title'] ?? null;

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        $code = $body['code'] ?? $body['error'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    /**
     * X's per-post cap is a 24-hour window, so a short retry would only consume
     * more of the same exhausted budget.
     */
    protected function fallbackRetryAfter(): int
    {
        return 900;
    }

    /**
     * @throws ProviderNotConfiguredException
     */
    protected function assertConfigured(): void
    {
        $credentials = (array) config('socialhub.providers.x.credentials', []);

        $missing = [];

        foreach (['client_id' => 'X_CLIENT_ID', 'client_secret' => 'X_CLIENT_SECRET'] as $key => $envKey) {
            $value = $credentials[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $envKey;
            }
        }

        if ($missing !== []) {
            throw new ProviderNotConfiguredException(
                self::KEY,
                sprintf('missing %s in .env', implode(', ', $missing)),
            );
        }
    }

    // ----------------------------------------------------------------- HTTP

    protected function api(SocialAccount $account): ProviderHttpClient
    {
        return $this->http
            ->withAccessToken($this->tokens->resolve($account, self::KEY)->accessToken)
            ->withTenant($this->tenantIdOf($account));
    }

    protected function oauthHttp(): ProviderHttpClient
    {
        return $this->http->withoutThrottle();
    }

    protected function assertSuccessful(Response $response, string $operation): void
    {
        $this->assertNotRateLimited($response);

        if (! $response->successful()) {
            $this->logProviderFailure($operation, $response);

            throw $this->buildApiException($response);
        }
    }

    protected function throwPaginationFailure(Response $response): ProviderApiException
    {
        return $this->buildApiException($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataFrom(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------------ Publishing

    /**
     * @return list<string>
     */
    protected function mediaIdsFor(PublishPayload $publish, string $type): array
    {
        if ($type !== PublishPayload::TYPE_CAROUSEL) {
            return $publish->mediaIds;
        }

        $this->assertUnsupported('carouselPublishing');

        return [];
    }

    protected function capabilityFor(string $type): string
    {
        return match ($type) {
            PublishPayload::TYPE_VIDEO => 'videoPublishing',
            PublishPayload::TYPE_IMAGE => 'imagePublishing',
            PublishPayload::TYPE_CAROUSEL => 'carouselPublishing',
            default => 'textPublishing',
        };
    }

    protected function appendChunks(SocialAccount $account, MediaAsset $media, string $mediaId): void
    {
        $contents = $this->mediaContents($media);
        $total = strlen($contents);
        $chunkSize = XConfig::CHUNK_SIZE;
        $chunks = $total > 0 ? (int) ceil($total / $chunkSize) : 1;

        for ($index = 0; $index < $chunks; $index++) {
            $response = $this->api($account)->post(
                sprintf('%s/%s/append', XConfig::API_BASE.XConfig::MEDIA_UPLOAD, $mediaId),
                [
                    'media' => base64_encode(substr($contents, $index * $chunkSize, $chunkSize)),
                    'segment_index' => (string) $index,
                ],
            );

            $this->assertSuccessful($response, 'uploadMedia.append');
        }
    }

    protected function finalizeUpload(SocialAccount $account, string $mediaId): Response
    {
        $response = $this->api($account)->post(
            sprintf('%s/%s/finalize', XConfig::API_BASE.XConfig::MEDIA_UPLOAD, $mediaId),
        );

        $this->assertSuccessful($response, 'uploadMedia.finalize');

        return $response;
    }

    /**
     * Honours `processing_info.check_after_secs` rather than polling on a fixed
     * schedule, and gives up on `failed` immediately because the same file will
     * fail again.
     */
    protected function awaitProcessing(SocialAccount $account, Response $final, string $mediaId): void
    {
        $attempts = max(1, (int) config('socialhub.providers.x.media_processing_attempts', 10));
        $wait = XConfig::DEFAULT_CHUNK_CHECK_AFTER_SECONDS;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $processing = (array) ($this->dataFrom($final)['processing_info'] ?? []);

            $state = (string) ($processing['state'] ?? 'succeeded');

            if ($state === 'succeeded') {
                return;
            }

            if ($state === 'failed') {
                throw $this->buildApiException($final, UserFacingError::make(
                    'media_processing_failed',
                    'X could not process this media, so it was not attached to the post.',
                    sprintf('The media upload reported processing_info.state=failed (%s).', ((array) ($processing['error'] ?? []))['message'] ?? 'unspecified'),
                    false,
                    'Check the file meets the size, duration, and codec limits X documents for this media category, then re-upload.',
                ));
            }

            if ($attempt === $attempts - 1) {
                break;
            }

            $wait = max(1, (int) ($processing['check_after_secs'] ?? $wait));
            sleep($wait);

            $final = $this->mediaStatus($account, $mediaId);
        }

        throw $this->buildApiException($final, UserFacingError::make(
            'media_processing',
            'X was still processing the media when the retry window ran out. The post will be retried.',
            sprintf('The media upload was still processing after %d status checks.', $attempts),
            true,
            'No action needed; the job is requeued and the upload is polled again.',
        ));
    }

    protected function mediaStatus(SocialAccount $account, string $mediaId): Response
    {
        $response = $this->api($account)->get(XConfig::API_BASE.XConfig::MEDIA_STATUS, [
            'command' => 'STATUS',
            'media_id' => $mediaId,
        ]);

        $this->assertSuccessful($response, 'uploadMedia.status');

        return $response;
    }

    protected function mediaContents(MediaAsset $media): string
    {
        $path = (string) ($this->attribute($media, 'storage_path') ?? '');

        if ($path === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'media_missing',
                'This media could not be uploaded because the file is missing from storage.',
                'The media asset has no storage_path.',
                false,
                'Re-upload the media, then republish the post.',
            ));
        }

        $disk = (string) (($this->attribute($media, 'storage_disk')) ?: config('socialhub.media.disk', 'local'));
        $contents = Storage::disk($disk)->get($path);

        if ($contents === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'media_empty',
                'This media could not be uploaded because the stored file is empty.',
                sprintf('The stored media file "%s" on disk "%s" is empty.', $path, $disk),
                false,
                'Re-upload the media, then republish the post.',
            ));
        }

        return $contents;
    }

    protected function mediaCategoryFor(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'video/') => 'tweet_video',
            $mimeType === 'image/gif' => 'tweet_gif',
            default => 'tweet_image',
        };
    }

    protected function maxBytesFor(string $category): int
    {
        return match ($category) {
            'tweet_video' => XConfig::MAX_VIDEO_BYTES,
            'tweet_gif' => XConfig::MAX_GIF_BYTES,
            default => XConfig::MAX_IMAGE_BYTES,
        };
    }

    // ------------------------------------------------------------- Insights

    /**
     * @param  list<string>  $only
     * @return list<MetricSample>
     */
    protected function publicMetrics(SocialAccount $account, string $postId, array $only = []): array
    {
        $response = $this->api($account)->get(sprintf('%s/2/tweets/%s', XConfig::API_BASE, $postId), [
            'tweet.fields' => 'public_metrics,created_at',
        ]);

        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $data = $this->dataFrom($response);
        $metrics = (array) ($data['public_metrics'] ?? []);

        return $this->samplesFrom($metrics, $only, (string) ($data['created_at'] ?? ''));
    }

    /**
     * @param  list<string>  $only
     * @return list<MetricSample>
     */
    protected function userMetrics(SocialAccount $account, array $only = []): array
    {
        $response = $this->api($account)->get(XConfig::API_BASE.'/2/users/me', [
            'user.fields' => 'public_metrics',
        ]);

        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $metrics = (array) ($this->dataFrom($response)['public_metrics'] ?? []);

        $samples = [];

        // The user-level metric X reports is a follower count, which matches the
        // normalised vocabulary. Tweet-level counters are not user totals and
        // are never summed into this one.
        if (isset($metrics['followers_count']) && ($only === [] || in_array('followers', $only, true))) {
            $samples[] = new MetricSample(
                metric: 'followers',
                value: (float) $metrics['followers_count'],
                granularity: 'current',
            );
        }

        return $samples;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $only
     * @return list<MetricSample>
     */
    protected function samplesFrom(array $metrics, array $only, string $createdAt): array
    {
        $samples = [];

        foreach (XConfig::PUBLIC_METRICS as $field => $normalised) {
            if ($only !== [] && ! in_array($normalised, $only, true)) {
                continue;
            }

            if (! isset($metrics[$field])) {
                continue;
            }

            $samples[] = new MetricSample(
                metric: $normalised,
                value: (float) $metrics[$field],
                periodEnd: $createdAt !== '' ? $createdAt : null,
                granularity: 'lifetime',
            );
        }

        return $samples;
    }

    // -------------------------------------------------------------- Helpers

    /**
     * @param  array<string, mixed>  $data
     */
    protected function profileFrom(array $data, SocialAccount $account): AccountProfile
    {
        $username = isset($data['username']) ? (string) $data['username'] : null;
        $metrics = (array) ($data['public_metrics'] ?? []);

        return new AccountProfile(
            provider: self::KEY,
            providerAccountId: (string) ($data['id'] ?? ''),
            username: $username,
            displayName: isset($data['name']) ? (string) $data['name'] : null,
            avatarUrl: $data['profile_image_url'] ?? null,
            accountType: (bool) ($data['verified'] ?? false) ? 'verified' : 'user',
            profileUrl: $username !== null ? 'https://x.com/'.$username : null,
            followerCount: isset($metrics['followers_count']) ? (int) $metrics['followers_count'] : null,
            grantedScopes: $this->grantedScopesOf($account),
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function contentTypeFrom(array $data): string
    {
        $attachments = (array) ($data['attachments'] ?? []);

        if ($attachments !== []) {
            return PublishPayload::TYPE_IMAGE;
        }

        return PublishPayload::TYPE_TEXT;
    }

    protected function userId(SocialAccount $account): ?string
    {
        $metadata = $this->metadataOf($account);
        $userId = $metadata['user_id'] ?? null;

        return is_string($userId) && $userId !== '' ? $userId : null;
    }
}
