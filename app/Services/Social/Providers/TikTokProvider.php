<?php

declare(strict_types=1);

namespace App\Services\Social\Providers;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\WebhookSignatureException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Config\TikTokConfig;
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
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\OAuth\UsesOAuth2AuthorizationCode;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\Providers\Concerns\GuardsProviderOperations;
use App\Services\Social\Providers\Concerns\MapsProviderErrors;
use App\Services\Social\Providers\Concerns\PaginatesCursor;
use App\Services\Social\Providers\Concerns\PublishesFromPostVariant;
use App\Services\Social\Providers\Concerns\ReadsConnectedAccount;
use App\Services\Social\Providers\Concerns\RespectsRateLimitHeaders;
use App\Services\Social\Support\AccountTokenResolver;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;

/**
 * TikTok Content Posting API.
 *
 * Two gates make this platform unusual and both are enforced here rather than
 * documented and hoped for. First, `video.publish` is only usable once the app
 * has passed TikTok App Audit; an unaudited client can only post privately and
 * TikTok rejects the call outright otherwise, which surfaces as
 * `unaudited_client_can_only_post_to_private_accounts`. Second, the creator
 * must complete TikTok's consent screen before any init call is accepted, so
 * `consentUrl()` produces the redirect a publishing flow has to send them to.
 *
 * The API has no scheduled publish parameter. `video.upload` creates an inbox
 * draft the creator finishes in the app, which is not the same thing as a
 * scheduled post, so scheduling is reported as unsupported.
 */
class TikTokProvider implements SocialProviderInterface
{
    use GuardsProviderOperations;
    use MapsProviderErrors;
    use PaginatesCursor;
    use PublishesFromPostVariant;
    use ReadsConnectedAccount;
    use RespectsRateLimitHeaders;
    use UsesOAuth2AuthorizationCode;

    public const KEY = 'tiktok';

    /**
     * video query field => normalised metric. TikTok's Open Platform reports no
     * reach or impression figure, so neither appears here.
     *
     * @var array<string, string>
     */
    private const VIDEO_METRICS = [
        'view_count' => 'views',
        'like_count' => 'likes',
        'comment_count' => 'comments',
        'share_count' => 'shares',
    ];

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
        return 'TikTok';
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        $overrides = (array) config('socialhub.providers.tiktok.capabilities_override', []);

        return PlatformCapabilities::tiktok()->withOverrides($overrides);
    }

    // ---------------------------------------------------------------- OAuth

    /**
     * The authorization leg a publishing flow needs: TikTok requires the
     * creator to be sent through its own consent screen, and the access token
     * from that exchange is what a Content Posting call will accept.
     */
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
     * The consent URL a creator must visit before this app can publish for them.
     */
    public function consentUrl(AuthRequest $request): string
    {
        $base = (string) (config('socialhub.providers.tiktok.oauth.consent_base') ?: 'https://www.tiktok.com/consent/');
        $state = $request->state ?? $this->newState();

        return sprintf('%s/?state=%s', rtrim($base, '/'), rawurlencode($state));
    }

    public function refreshToken(SocialAccount $account): TokenSet
    {
        $this->assertConfigured();

        $stored = $this->tokens->resolve($account, self::KEY);

        if ($stored->refreshToken === null || $stored->refreshToken === '') {
            return $stored;
        }

        return $this->refreshAccessToken($stored->refreshToken, $this->oauthHttp());
    }

    // -------------------------------------------------------------- Account

    public function getAccount(SocialAccount $account): AccountProfile
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(TikTokConfig::API_BASE.TikTokConfig::USER_INFO, [
            'fields' => TikTokConfig::USER_INFO_FIELDS,
        ]);

        $this->assertSuccessful($response, 'getAccount');

        $data = $this->tiktokUserFrom($response);

        return new AccountProfile(
            provider: self::KEY,
            providerAccountId: (string) ($data['open_id'] ?? ''),
            username: isset($data['username']) ? (string) $data['username'] : null,
            displayName: isset($data['display_name']) ? (string) $data['display_name'] : null,
            avatarUrl: $data['avatar_url'] ?? null,
            accountType: 'creator',
            profileUrl: isset($data['username']) ? 'https://www.tiktok.com/@'.$data['username'] : null,
            followerCount: isset($data['follower_count']) ? (int) $data['follower_count'] : null,
            grantedScopes: $this->grantedScopesOf($account),
            raw: $data,
        );
    }

    /**
     * TikTok has no notion of sub-accounts, so there is nothing to list.
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

        $response = $this->api($account)->get(TikTokConfig::API_BASE.TikTokConfig::USER_INFO, [
            'fields' => 'username,follower_count,video_count',
        ]);

        $this->assertSuccessful($response, 'getFollowers');

        $data = $this->tiktokUserFrom($response);
        $followers = (int) ($data['follower_count'] ?? 0);

        return new FollowerStats(
            provider: self::KEY,
            providerAccountId: (string) ($data['open_id'] ?? $this->accountIdOf($account)),
            total: $followers,
            breakdown: ['followers' => $followers],
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
            // No scheduled_publish field exists in the Content Posting API.
            $this->assertUnsupported('scheduling');
        }

        $this->assertAudited($publish);

        $isCarousel = $publish->contentType() === PublishPayload::TYPE_CAROUSEL || count($publish->mediaIds) > 1;

        $response = $isCarousel
            ? $this->initPhotoPost($account, $publish)
            : $this->initVideoPost($account, $publish);

        $this->assertSuccessful($response, 'createPost');

        $data = $this->tiktokDataFrom($response);
        $publishId = (string) ($data['publish_id'] ?? '');

        if ($publishId === '') {
            throw $this->buildApiException($response, UserFacingError::make(
                'publish_id_missing',
                'TikTok accepted the request but returned no publish id, so the post was not started.',
                'The init response carried no data.publish_id.',
                true,
                'Retry the publish; if it keeps failing, check the publish status endpoint for the outcome.',
            ));
        }

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: $publishId,
            permalink: null,
            text: $publish->text,
            contentType: $publish->contentType(),
            attachedMediaIds: $publish->mediaIds,
            publishedAt: new DateTimeImmutable,
            raw: $data,
        );
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        return $this->createPost($account, ['payload' => $this->payloadFromVariant($variant)]);
    }

    /**
     * Creates the inbox draft TikTok hands to the creator.
     *
     * `video.upload` is a real upload with `post_mode` MEDIA_UPLOAD: the video
     * lands in the creator's TikTok inbox for them to finish and post from the
     * app. That is a draft, not a published post and not a scheduled one, so
     * the returned publish_id is described honestly rather than as a live post.
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        $this->assertUnsupported('videoPublishing');
        $this->assertConfigured();

        $this->assertWithinByteLimit(
            (int) ($this->attribute($media, 'file_size') ?? 0),
            TikTokConfig::MAX_VIDEO_BYTES,
            'Upload a smaller video; TikTok accepts files up to 4 GB on the Content Posting API.',
        );

        $url = $this->mediaUrlOf((array) ($this->attribute($media, 'metadata') ?? []))
            ?? (($this->attribute($media, 'metadata')['public_url'] ?? null));

        if (! is_string($url) || $url === '') {
            throw $this->unsupportedContentFormat(
                'publicMediaUrl',
                'TikTok pulls media from a URL whose domain this app has verified, so add the public media URL to the asset metadata before uploading.',
            );
        }

        $response = $this->api($account)->post(TikTokConfig::API_BASE.TikTokConfig::UPLOAD_VIDEO, [
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $url,
            ],
        ]);

        if (! $response->successful()) {
            $this->logProviderFailure('uploadMedia', $response);

            throw $this->buildApiException($response);
        }

        $data = $this->tiktokDataFrom($response);
        $publishId = (string) ($data['publish_id'] ?? '');

        if ($publishId === '') {
            throw $this->buildApiException($response, UserFacingError::make(
                'publish_id_missing',
                'TikTok accepted the upload but returned no publish id, so the draft was not created.',
                'The upload init response carried no data.publish_id.',
                true,
                'Retry the upload; if it keeps failing, check the publish status endpoint for the outcome.',
            ));
        }

        return $publishId;
    }

    /**
     * TikTok does not allow deleting published videos. A publish that has not
     * been uploaded yet can only be cancelled with its publish_id, so the honest
     * answer here is that the platform cannot do it.
     */
    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        $this->assertUnsupported('deletePost');
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(TikTokConfig::API_BASE.TikTokConfig::VIDEO_QUERY, [
            'fields' => 'id,create_time,title,video_description,duration,cover_image_url,share_url,embed_html,embed_link,like_count,comment_count,share_count,view_count',
            'video_ids' => $providerPostId,
        ]);

        $this->assertSuccessful($response, 'getPost');

        $data = $this->tiktokVideosFrom($response);

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: (string) ($data['id'] ?? $providerPostId),
            permalink: $data['share_url'] ?? $data['embed_link'] ?? null,
            text: isset($data['title']) ? (string) $data['title'] : null,
            contentType: PublishPayload::TYPE_VIDEO,
            publishedAt: $this->readDate($data, 'create_time'),
            createdAt: $this->readDate($data, 'create_time'),
            raw: $data,
        );
    }

    // ------------------------------------------------------------- Comments

    /**
     * @return list<ProviderComment>
     */
    public function getComments(SocialAccount $account, ?string $providerPostId = null): array
    {
        $this->assertUnsupported('comments');
        $this->assertConfigured();

        if ($providerPostId === null || $providerPostId === '') {
            throw $this->unsupportedContentFormat(
                'comments',
                'TikTok comments are readable per video only. Pass the id of the specific video whose comments you want.',
            );
        }

        $limit = (int) config('socialhub.pagination.default_page_size', 50);

        $pages = $this->paginate(
            fn (?string $cursor): Response => $this->api($account)->get(TikTokConfig::API_BASE.TikTokConfig::COMMENT_LIST, array_filter([
                'video_id' => $providerPostId,
                'max_count' => min(100, $limit),
                'cursor' => (int) $cursor,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')),
            fn (array $body): ?string => $this->tiktokCursorFrom($body),
        );

        $comments = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($this->tiktokCommentPage($page), 'comments')) as $comment) {
            $user = (array) ($comment['user'] ?? []);

            $comments[] = new ProviderComment(
                provider: self::KEY,
                providerCommentId: (string) ($comment['cid'] ?? ''),
                providerPostId: $providerPostId,
                content: (string) ($comment['text'] ?? ''),
                parentProviderCommentId: isset($comment['reply_id']) ? (string) $comment['reply_id'] : null,
                authorProviderId: isset($user['open_id']) ? (string) $user['open_id'] : null,
                authorUsername: isset($user['username']) ? (string) $user['username'] : null,
                authorDisplayName: isset($user['display_name']) ? (string) $user['display_name'] : null,
                authorAvatarUrl: $user['avatar_url'] ?? null,
                likeCount: (int) ($comment['digg_count'] ?? 0),
                replyCount: (int) ($comment['reply_count'] ?? 0),
                createdAt: $this->readDate($comment, 'create_time'),
                raw: $comment,
            );
        }

        return $comments;
    }

    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        $this->assertUnsupported('commentReplies');
        $this->assertConfigured();

        $response = $this->api($account)->post(TikTokConfig::API_BASE.TikTokConfig::COMMENT_REPLY, [
            'video_id' => $comment->providerPostId,
            'comment_id' => $comment->providerCommentId,
            'text' => $body,
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
            $samples = $this->videoMetrics($account, (string) $postId, $query->metrics);

            if ($samples !== []) {
                $postMetrics[(string) $postId] = $samples;
            }
        }

        return new AnalyticsBatch(
            provider: self::KEY,
            periodStart: $query->from,
            periodEnd: $query->to,
            accountMetrics: [],
            postMetrics: $postMetrics,
            granularity: $query->granularity,
        );
    }

    // ------------------------------------------------------------ Lifecycle

    public function disconnect(SocialAccount $account): void
    {
        $this->assertConfigured();

        // TikTok revokes a user's grant from the app's own settings; there is no
        // documented revoke endpoint to call, so there is nothing to fake here.
    }

    // ------------------------------------------------------------- Webhooks

    /**
     * TikTok signs webhook deliveries with an HMAC-SHA256 of the raw body keyed
     * on the client secret, sent as `TikTok-Webhook-Signature`.
     */
    public function verifyWebhook(VerifiedWebhookRequest $request): bool
    {
        $secret = (string) (config('socialhub.providers.tiktok.oauth.webhook_secret') ?: '');

        if ($secret === '') {
            throw new ProviderNotConfiguredException(
                self::KEY,
                'the TikTok client secret used for webhook signatures is not configured',
            );
        }

        $signature = $request->header('TikTok-Webhook-Signature');

        if ($signature === null || $signature === '') {
            throw new WebhookSignatureException(self::KEY, WebhookSignatureException::REASON_MISSING_SIGNATURE);
        }

        if (! hash_equals(hash_hmac('sha256', $request->rawBody, $secret), $signature)) {
            throw new WebhookSignatureException(self::KEY, WebhookSignatureException::REASON_SIGNATURE);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeWebhook(array $payload): array
    {
        $data = $payload['data'] ?? [];

        return [
            'provider' => self::KEY,
            'event_type' => (string) ($payload['event'] ?? $payload['type'] ?? 'unknown'),
            'event_id' => (string) ($payload['id'] ?? ''),
            'changes' => [[
                'field' => $payload['event'] ?? $payload['type'] ?? null,
                'object_id' => is_array($data) ? ($data['id'] ?? null) : null,
                'created_at' => $payload['event_time'] ?? null,
            ]],
        ];
    }

    // ---------------------------------------------------------------- Errors

    /**
     * @return array<string, UserFacingError>
     */
    protected function errorMap(): array
    {
        return TikTokConfig::errorMap();
    }

    protected function extractErrorCode(Response $response): ?string
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? []) : [];
        $code = is_array($error) ? ($error['code'] ?? null) : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @throws ProviderNotConfiguredException
     */
    protected function assertConfigured(): void
    {
        $credentials = (array) config('socialhub.providers.tiktok.credentials', []);

        $missing = [];

        foreach (['client_key' => 'TIKTOK_CLIENT_KEY', 'client_secret' => 'TIKTOK_CLIENT_SECRET'] as $key => $envKey) {
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

    // ------------------------------------------------------------ Publishing

    /**
     * TikTok restricts unaudited clients to private posts. Rather than letting
     * the request fail at the far end, the privacy level is forced to the one
     * value every client is permitted to use, and the user is told the post is
     * private until the app is audited.
     */
    protected function assertAudited(PublishPayload $publish): void
    {
        if ((bool) ($publish->options['app_audited'] ?? false)) {
            return;
        }

        $requested = (string) ($publish->options['privacy_level'] ?? '');

        if ($requested === '' || $requested === TikTokConfig::SAFE_PRIVACY_LEVEL) {
            return;
        }

        throw new ProviderApiException(
            self::KEY,
            0,
            'unaudited_client_can_only_post_to_private_accounts',
            UserFacingError::make(
                'app_audit_required',
                'This TikTok app has not passed App Audit, so it can only publish privately. Change this post to a private TikTok post, or submit the app for audit.',
                'The variant requests a public privacy level but the app is unaudited.',
                false,
                'Set the post privacy level to SELF_ONLY, or submit the app for TikTok App Audit before publishing publicly.',
            ),
        );
    }

    protected function initVideoPost(SocialAccount $account, PublishPayload $publish): Response
    {
        $this->assertUnsupported('videoPublishing');

        $source = (string) ($publish->options['source'] ?? 'PULL_FROM_URL');

        return $this->api($account)->post(TikTokConfig::API_BASE.TikTokConfig::DIRECT_POST_VIDEO, [
            'post_info' => $this->postInfo($publish),
            'source_info' => $this->videoSourceInfo($publish, $source),
        ]);
    }

    /**
     * A photo post carries up to 35 images, which is TikTok's carousel.
     */
    protected function initPhotoPost(SocialAccount $account, PublishPayload $publish): Response
    {
        $this->assertUnsupported('carouselPublishing');

        $images = [];

        foreach ($publish->mediaIds as $index => $mediaId) {
            $url = $this->mediaUrlOf($publish->options, (int) $index) ?? (is_string($mediaId) ? $mediaId : null);

            if ($url === null || ! str_starts_with($url, 'https://')) {
                throw $this->unsupportedContentFormat(
                    'publicMediaUrl',
                    'TikTok pulls photo posts from verified HTTPS URLs. Add a public URL to every image on this variant.',
                );
            }

            $images[] = $url;
        }

        $response = $this->api($account)->post(TikTokConfig::API_BASE.TikTokConfig::PHOTO_POST, [
            'post_info' => $this->postInfo($publish),
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_cover_index' => 0,
                'photo_images' => $images,
            ],
            'post_mode' => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function postInfo(PublishPayload $publish): array
    {
        $info = [
            'title' => $publish->text,
            'privacy_level' => $this->privacyLevel($publish),
        ];

        $disableComment = (bool) ($publish->options['disable_comment'] ?? false);
        $disableDuet = (bool) ($publish->options['disable_duet'] ?? false);
        $disableStitch = (bool) ($publish->options['disable_stitch'] ?? false);

        if ($disableComment) {
            $info['disable_comment'] = true;
        }

        if ($disableDuet) {
            $info['disable_duet'] = true;
        }

        if ($disableStitch) {
            $info['disable_stitch'] = true;
        }

        if (isset($publish->options['cover_timestamp_ms'])) {
            $info['video_cover_timestamp_ms'] = (int) $publish->options['cover_timestamp_ms'];
        }

        if ($publish->title !== null && $publish->title !== '' && $publish->title !== $publish->text) {
            $info['description'] = $publish->title;
        }

        return $info;
    }

    protected function privacyLevel(PublishPayload $publish): string
    {
        $level = (string) ($publish->options['privacy_level'] ?? TikTokConfig::SAFE_PRIVACY_LEVEL);

        if (! in_array($level, TikTokConfig::PRIVACY_LEVELS, true)) {
            throw $this->unsupportedContentFormat(
                'privacyLevel',
                'That is not a TikTok privacy level. Query the creator info endpoint and offer the creator exactly the levels TikTok returned for their account.',
            );
        }

        if ($level !== TikTokConfig::SAFE_PRIVACY_LEVEL && ! (bool) ($publish->options['app_audited'] ?? false)) {
            return TikTokConfig::SAFE_PRIVACY_LEVEL;
        }

        return $level;
    }

    /**
     * @return array<string, mixed>
     */
    protected function videoSourceInfo(PublishPayload $publish, string $source): array
    {
        if ($source === 'FILE_UPLOAD') {
            $size = (int) ($publish->options['video_size'] ?? 0);
            $chunkSize = TikTokConfig::CHUNK_SIZE;

            return [
                'source' => 'FILE_UPLOAD',
                'video_size' => $size,
                'chunk_size' => $chunkSize,
                'total_chunk_count' => $size > 0 ? (int) ceil($size / $chunkSize) : 1,
            ];
        }

        $url = $this->mediaUrlOf($publish->options)
            ?? (isset($publish->mediaIds[0]) && is_string($publish->mediaIds[0]) ? $publish->mediaIds[0] : null);

        if ($url === null || ! str_starts_with($url, 'https://')) {
            throw $this->unsupportedContentFormat(
                'publicMediaUrl',
                'TikTok pulls the video from a verified HTTPS URL. Add that URL to the variant media, or set source to FILE_UPLOAD with the file size.',
            );
        }

        return [
            'source' => 'PULL_FROM_URL',
            'video_url' => $url,
        ];
    }

    /**
     * @param  list<string>  $only
     * @return list<MetricSample>
     */
    protected function videoMetrics(SocialAccount $account, string $videoId, array $only = []): array
    {
        $response = $this->api($account)->get(TikTokConfig::API_BASE.TikTokConfig::VIDEO_QUERY, [
            'fields' => 'id,view_count,like_count,comment_count,share_count',
            'video_ids' => $videoId,
        ]);

        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $data = $this->tiktokVideosFrom($response);

        $samples = [];

        foreach (self::VIDEO_METRICS as $field => $normalised) {
            if ($only !== [] && ! in_array($normalised, $only, true)) {
                continue;
            }

            if (! isset($data[$field])) {
                continue;
            }

            $samples[] = new MetricSample(
                metric: $normalised,
                value: (float) $data[$field],
                granularity: 'lifetime',
            );
        }

        return $samples;
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
     * TikTok wraps every successful payload in `{ "data": { ... } }` and puts
     * failures in `{ "error": { "code", "message" } }`.
     *
     * @return array<string, mixed>
     */
    protected function tiktokDataFrom(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function tiktokUserFrom(Response $response): array
    {
        $data = $this->tiktokDataFrom($response);
        $user = $data['user'] ?? [];

        return is_array($user) ? $user : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function tiktokVideosFrom(Response $response): array
    {
        $data = $this->tiktokDataFrom($response);
        $videos = $data['videos'] ?? [];

        if (! is_array($videos)) {
            return [];
        }

        $first = $videos[0] ?? [];

        return is_array($first) ? $first : [];
    }

    /**
     * `/v2/comment/list/` nests the rows under a `comments` envelope that also
     * carries the pagination cursor, so the rows cannot be read straight off the
     * response body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function tiktokCommentPage(array $body): array
    {
        $envelope = $body['comments'] ?? null;

        return is_array($envelope) ? $envelope : $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function tiktokCursorFrom(array $body): ?string
    {
        $comments = $this->tiktokCommentPage($body);

        if (! (bool) ($comments['has_more'] ?? false)) {
            return null;
        }

        $cursor = $comments['cursor'] ?? null;

        return is_numeric($cursor) ? (string) $cursor : null;
    }
}
