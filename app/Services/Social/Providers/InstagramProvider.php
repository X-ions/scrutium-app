<?php

declare(strict_types=1);

namespace App\Services\Social\Providers;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Config\InstagramConfig;
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
use App\Services\Social\Providers\Concerns\UsesMetaGraph;
use App\Services\Social\Support\AccountTokenResolver;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Instagram Platform, reached through a Facebook Login.
 *
 * Content publishing is a genuine two-phase flow: `POST /{ig-id}/media` creates
 * a container, Instagram fetches and transcodes the media asynchronously, and
 * only once the container reports FINISHED does `POST /{ig-id}/media_publish`
 * turn it into a real post. Both calls really are made, with a bounded poll in
 * between — publishing blind loses the post, so there is no shortcut here.
 */
class InstagramProvider implements SocialProviderInterface
{
    use GuardsProviderOperations;
    use MapsProviderErrors;
    use PaginatesCursor;
    use PublishesFromPostVariant;
    use ReadsConnectedAccount;
    use RespectsRateLimitHeaders;
    use UsesMetaGraph;
    use UsesOAuth2AuthorizationCode;

    public const KEY = 'instagram';

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
        return 'Instagram';
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        $overrides = (array) config('socialhub.providers.instagram.capabilities_override', []);

        return PlatformCapabilities::instagram()->withOverrides($overrides);
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

        $response = $this->graph($account)->get($this->graphUrl($this->igId($account).'/'), [
            'fields' => 'id,username,name,biography,profile_picture_url,followers_count,media_count,website',
        ]);

        $this->assertSuccessful($response, 'getAccount');

        return $this->profileFrom((array) $response->json(), $account);
    }

    /**
     * Instagram accounts behind every Facebook Page the connected identity
     * administers. A Page with no linked professional account is skipped rather
     * than surfaced as an Instagram target.
     *
     * @return list<AccountProfile>
     */
    public function getPages(SocialAccount $account): array
    {
        $this->assertConfigured();

        $limit = (int) config('socialhub.pagination.default_page_size', 50);

        $pages = $this->paginate(
            fn (?string $after): Response => $this->graph($account)->get($this->graphUrl('me/accounts'), array_filter([
                'fields' => 'id,name,instagram_business_account{id,username,name,profile_picture_url,followers_count,media_count}',
                'limit' => $limit,
                'after' => $after,
            ], static fn (mixed $value): bool => $value !== null)),
            fn (array $body): ?string => $this->cursorFrom($body),
        );

        $profiles = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($page)) as $page) {
            $ig = (array) ($page['instagram_business_account'] ?? []);

            if ($ig === []) {
                continue;
            }

            $profiles[] = $this->profileFrom($ig, $account, [
                'page_id' => $page['id'] ?? null,
                'page_name' => $page['name'] ?? null,
            ] + $ig);
        }

        return $profiles;
    }

    public function getFollowers(SocialAccount $account): FollowerStats
    {
        $this->assertUnsupported('followers');
        $this->assertConfigured();

        $response = $this->graph($account)->get($this->graphUrl($this->igId($account).'/'), [
            'fields' => 'followers_count,media_count',
        ]);

        $this->assertSuccessful($response, 'getFollowers');

        $data = (array) $response->json();
        $followers = (int) ($data['followers_count'] ?? 0);

        return new FollowerStats(
            provider: self::KEY,
            providerAccountId: $this->igId($account),
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
            // Instagram has no publishAt field; the queue is the scheduler.
            $this->assertUnsupported('scheduling');
        }

        $igId = $this->igId($account);
        $containerId = $this->createContainer($account, $igId, $publish);

        $this->awaitContainerReady($account, $containerId);

        $response = $this->graph($account)->post($this->graphUrl($igId.'/media_publish'), [
            'creation_id' => $containerId,
        ]);

        $this->assertSuccessful($response, 'createPost');

        $data = (array) $response->json();
        $mediaId = (string) ($data['id'] ?? '');

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: $mediaId,
            permalink: $mediaId === '' ? null : 'https://www.instagram.com/p/'.$mediaId,
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
     * Creates the container a Reel or Stories video is pushed into, returning
     * the container id `createPost()` will later publish.
     *
     * A URL is not sufficient for a Reel or a Story: Instagram requires the
     * bytes to be pushed to `rupload.facebook.com/ig-api-upload/{container}` in
     * a resumable session. An image is different — it is referenced by public
     * URL at container-creation time, so there is no upload step and therefore
     * no asset id to return.
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        $this->assertConfigured();

        $isVideo = str_starts_with((string) ($this->attribute($media, 'mime_type') ?? ''), 'video/');

        $this->assertUnsupported($isVideo ? 'videoPublishing' : 'imagePublishing');

        if (! $isVideo) {
            throw $this->unsupportedContentFormat(
                'imageUpload',
                'Instagram fetches images from a public HTTPS URL when the container is created, so there is no separate image upload. Put the public image URL on the variant instead.',
            );
        }

        $this->assertWithinByteLimit(
            (int) ($this->attribute($media, 'file_size') ?? 0),
            InstagramConfig::MAX_REEL_BYTES,
            'Re-upload a smaller video; Instagram accepts up to 1 GB per Reel.',
        );

        $init = $this->graph($account)->post($this->graphUrl($this->igId($account).'/media'), array_filter([
            'upload_type' => 'resumable',
            'media_type' => $this->requestedMediaType($media),
            'is_carousel_item' => (bool) (($this->attribute($media, 'metadata')['is_carousel_item'] ?? false)) ?: null,
        ], static fn (mixed $value): bool => $value !== null));

        $this->assertSuccessful($init, 'uploadMedia.init');

        $body = (array) $init->json();
        $containerId = (string) ($body['id'] ?? $body['ig-container-id'] ?? '');
        $sessionUrl = (string) ($body['upload_url'] ?? $body['rupload_uri'] ?? '');

        if ($containerId === '' || $sessionUrl === '') {
            throw $this->apiException($init, UserFacingError::make(
                'upload_session_missing',
                'Instagram did not return a resumable upload session for this video.',
                'The /media resumable init response carried no container id or upload_url.',
                true,
                'Retry the upload; if it keeps failing, check that instagram_content_publish is approved for this app.',
            ));
        }

        $this->pushVideoBytes($account, $media, $sessionUrl);

        return $containerId;
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        $this->assertUnsupported('deletePost');
        $this->assertConfigured();

        $response = $this->graph($account)->delete($this->graphUrl($providerPostId));

        $this->assertSuccessful($response, 'deletePost');

        return $response->successful();
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        $this->assertConfigured();

        $response = $this->graph($account)->get($this->graphUrl($providerPostId), [
            'fields' => 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count,username',
        ]);

        $this->assertSuccessful($response, 'getPost');

        $data = (array) $response->json();
        $mediaId = (string) ($data['id'] ?? $providerPostId);

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: $mediaId,
            permalink: isset($data['permalink']) ? (string) $data['permalink'] : 'https://www.instagram.com/p/'.$mediaId,
            text: isset($data['caption']) ? (string) $data['caption'] : null,
            contentType: $this->contentTypeFrom($data),
            publishedAt: $this->readDate($data, 'timestamp'),
            createdAt: $this->readDate($data, 'timestamp'),
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
                'Instagram comments are readable per media item only. Pass the id of the specific post whose comments you want.',
            );
        }

        $limit = (int) config('socialhub.pagination.default_page_size', 50);

        $pages = $this->paginate(
            fn (?string $after): Response => $this->graph($account)->get(
                $this->graphUrl($providerPostId.'/comments'),
                array_filter([
                    'fields' => 'id,text,username,user{id},timestamp,like_count,media_id',
                    'limit' => $limit,
                    'after' => $after,
                ], static fn (mixed $value): bool => $value !== null),
            ),
            fn (array $body): ?string => $this->cursorFrom($body),
        );

        $comments = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($page)) as $comment) {
            $comments[] = new ProviderComment(
                provider: self::KEY,
                providerCommentId: (string) ($comment['id'] ?? ''),
                providerPostId: $providerPostId,
                content: (string) ($comment['text'] ?? ''),
                authorProviderId: isset($comment['user']['id']) ? (string) $comment['user']['id'] : null,
                authorUsername: isset($comment['username']) ? (string) $comment['username'] : null,
                likeCount: (int) ($comment['like_count'] ?? 0),
                createdAt: $this->readDate($comment, 'timestamp'),
                raw: $comment,
            );
        }

        return $comments;
    }

    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        $this->assertUnsupported('commentReplies');
        $this->assertConfigured();

        $response = $this->graph($account)->post($this->graphUrl($comment->providerCommentId.'/replies'), [
            'message' => $body,
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
            $samples = $this->insightSamples(
                $account,
                $this->graphUrl((string) $postId.'/insights'),
                ['metric' => implode(',', $this->graphMetricsFor($query))],
            );

            if ($samples !== []) {
                $postMetrics[(string) $postId] = $samples;
            }
        }

        return new AnalyticsBatch(
            provider: self::KEY,
            periodStart: $query->from,
            periodEnd: $query->to,
            accountMetrics: $this->insightSamples(
                $account,
                $this->graphUrl($this->igId($account).'/insights'),
                array_filter([
                    'metric' => implode(',', $this->graphMetricsFor($query)),
                    'period' => 'day',
                    'since' => $query->from->format('Y-m-d'),
                    'until' => $query->to->format('Y-m-d'),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ),
            postMetrics: $postMetrics,
            granularity: $query->granularity,
        );
    }

    // ------------------------------------------------------------ Lifecycle

    public function disconnect(SocialAccount $account): void
    {
        $this->assertConfigured();

        $this->deauthorizeQuietly($account);
    }

    // ------------------------------------------------------------- Webhooks

    public function verifyWebhook(VerifiedWebhookRequest $request): bool
    {
        $this->verifyMetaSignature($request);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeWebhook(array $payload): array
    {
        $entry = $payload['entry'][0] ?? null;

        if (! is_array($entry)) {
            return [
                'provider' => self::KEY,
                'event_type' => (string) ($payload['object'] ?? 'unknown'),
                'event_id' => null,
                'changes' => [],
                'raw' => $payload,
            ];
        }

        $changes = [];

        foreach ((array) ($entry['changes'] ?? []) as $change) {
            if (! is_array($change)) {
                continue;
            }

            $value = (array) ($change['value'] ?? []);

            $changes[] = [
                'field' => $change['field'] ?? null,
                'verb' => $value['verb'] ?? null,
                'object' => $value['object'] ?? null,
                'object_id' => $value['media_id'] ?? $value['comment_id'] ?? $value['id'] ?? null,
                'sender' => $value['from'] ?? null,
                'created_at' => $value['created_time'] ?? null,
            ];
        }

        return [
            'provider' => self::KEY,
            'event_type' => (string) ($entry['changes'][0]['field'] ?? $payload['object'] ?? 'unknown'),
            'event_id' => (string) ($payload['event_id'] ?? $entry['id'] ?? ''),
            'page_id' => $entry['id'] ?? null,
            'changes' => $changes,
        ];
    }

    // ---------------------------------------------------------------- Errors

    /**
     * @return array<string, UserFacingError>
     */
    protected function errorMap(): array
    {
        return InstagramConfig::errorMap();
    }

    /**
     * @throws ProviderNotConfiguredException
     */
    protected function assertConfigured(): void
    {
        $this->assertCredentialsPresent([
            'client_id' => 'META_APP_ID',
            'client_secret' => 'META_APP_SECRET',
        ]);
    }

    // ------------------------------------------------------------ Publishing

    /**
     * Phase one. A single image, video, or reel becomes one container; a
     * carousel becomes one container per child plus a parent referencing them.
     */
    protected function createContainer(SocialAccount $account, string $igId, PublishPayload $publish): string
    {
        return match ($publish->contentType()) {
            PublishPayload::TYPE_CAROUSEL => $this->createCarouselContainer($account, $igId, $publish),
            PublishPayload::TYPE_REEL, PublishPayload::TYPE_STORY, PublishPayload::TYPE_VIDEO => $this->createVideoContainer($account, $igId, $publish),
            PublishPayload::TYPE_IMAGE => $this->createImageContainer($account, $igId, $publish),
            default => throw $this->unsupportedContentFormat(
                'textPublishing',
                'Instagram requires a photo, video, reel, carousel, or story; a caption alone cannot be published. Attach a media asset or a public media URL to this variant.',
            ),
        };
    }

    protected function createImageContainer(SocialAccount $account, string $igId, PublishPayload $publish): string
    {
        $altText = (string) ($publish->options['alt_text'] ?? '');

        $response = $this->graph($account)->post($this->graphUrl($igId.'/media'), array_filter([
            'image_url' => $this->publicMediaUrl($publish, 0, 'image'),
            'caption' => $publish->text,
            'alt_text' => $altText !== '' ? $altText : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $this->containerIdFrom($response, 'createPost.image');
    }

    /**
     * Videos and reels differ only in `media_type`. `share_to_feed` keeps a reel
     * in both the Reels and the Feed tab; it does not apply to a plain video.
     */
    protected function createVideoContainer(SocialAccount $account, string $igId, PublishPayload $publish): string
    {
        $mediaType = match ($publish->contentType()) {
            PublishPayload::TYPE_REEL => 'REELS',
            PublishPayload::TYPE_STORY => 'STORIES',
            default => 'VIDEO',
        };

        $response = $this->graph($account)->post($this->graphUrl($igId.'/media'), array_filter([
            'media_type' => $mediaType,
            'video_url' => $this->publicMediaUrl($publish, 0, 'video'),
            'caption' => $publish->text,
            'share_to_feed' => $mediaType === 'REELS' ? ($publish->options['share_to_feed'] ?? true) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $this->containerIdFrom($response, 'createPost.video');
    }

    /**
     * Reels cannot appear in a carousel, so a carousel child that is a video
     * uses the plain VIDEO media type.
     */
    protected function createCarouselContainer(SocialAccount $account, string $igId, PublishPayload $publish): string
    {
        $this->assertUnsupported('carouselPublishing');

        $children = [];

        foreach ($publish->mediaIds as $mediaId) {
            $child = $this->isVideoUrl((string) $mediaId)
                ? $this->graph($account)->post($this->graphUrl($igId.'/media'), [
                    'media_type' => 'VIDEO',
                    'video_url' => $mediaId,
                    'is_carousel_item' => true,
                ])
                : $this->graph($account)->post($this->graphUrl($igId.'/media'), [
                    'image_url' => $mediaId,
                    'is_carousel_item' => true,
                ]);

            $children[] = $this->containerIdFrom($child, 'createPost.carouselItem');
        }

        if (count($children) < 2) {
            throw new ProviderApiException(
                self::KEY,
                0,
                null,
                UserFacingError::make(
                    'carousel_too_small',
                    'An Instagram carousel needs at least two children.',
                    sprintf('The carousel was built with %d child container(s); Instagram requires 2 to %d.', count($children), InstagramConfig::MAX_CAROUSEL_CHILDREN),
                    false,
                    'Attach two or more media assets to this variant, or publish it as a single image instead.',
                ),
            );
        }

        $response = $this->graph($account)->post($this->graphUrl($igId.'/media'), [
            'media_type' => 'CAROUSEL',
            'children' => $children,
            'caption' => $publish->text,
        ]);

        return $this->containerIdFrom($response, 'createPost.carousel');
    }

    /**
     * Polls `/{container}?fields=status_code` until Instagram reports FINISHED.
     *
     * The bounded poll is the honest behaviour: publishing a container that is
     * still transcoding loses the post, and waiting forever is not an option
     * either, so an unfinished container is a retryable error.
     */
    protected function awaitContainerReady(SocialAccount $account, string $containerId): void
    {
        $attempts = max(1, (int) config('socialhub.providers.instagram.container_poll_attempts', InstagramConfig::CONTAINER_POLL_ATTEMPTS));
        $interval = (int) config('socialhub.providers.instagram.container_poll_interval', InstagramConfig::CONTAINER_POLL_INTERVAL_SECONDS);

        $status = 'IN_PROGRESS';

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $response = $this->graph($account)->get($this->graphUrl($containerId), [
                'fields' => 'status_code,status',
            ]);

            $this->assertSuccessful($response, 'createPost.containerStatus');

            $status = strtoupper((string) ((array) $response->json())['status_code'] ?? '');

            if (in_array($status, ['FINISHED', 'PUBLISHED'], true)) {
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw $this->apiException($response, UserFacingError::make(
                    $status === 'EXPIRED' ? 'container_expired' : 'container_failed',
                    $status === 'EXPIRED'
                        ? 'Instagram expired this media before it was published. The post will be retried with a fresh container.'
                        : 'Instagram could not process this media. The post will be retried.',
                    sprintf('The IG media container reported status_code=%s.', $status),
                    true,
                    'No action needed; the job is requeued and a new container is created.',
                    ['status_code' => $status],
                ));
            }

            if ($attempt < $attempts - 1) {
                sleep($interval);
            }
        }

        // A 200 that simply never reported FINISHED is not a provider error, so
        // the exception is built directly: routing it through the response
        // mapper would report a "provider unreachable" that did not happen.
        throw new ProviderApiException(
            self::KEY,
            0,
            'container_unfinished',
            UserFacingError::make(
                'container_unfinished',
                'Instagram had not finished processing the media in time. The post will be retried.',
                sprintf('The IG media container was still %s after %d polls.', $status, $attempts),
                true,
                'No action needed; the job is requeued and the container is polled again.',
                ['status_code' => $status, 'attempts' => $attempts],
            ),
        );
    }

    /**
     * Instagram's `next_offset` response header drives the loop. A zero header
     * on a successful response means the transfer is complete.
     */
    protected function pushVideoBytes(SocialAccount $account, MediaAsset $media, string $sessionUrl): void
    {
        $contents = $this->mediaContents($media);
        $total = strlen($contents);
        $offset = 0;

        while ($offset < $total) {
            $response = Http::withToken($this->graphTokenFor($account))
                ->timeout((int) config('socialhub.publishing.timeout', 30))
                ->connectTimeout((int) config('socialhub.publishing.connect_timeout', 10))
                ->withHeaders([
                    'offset' => (string) $offset,
                    'file_size' => (string) $total,
                ])
                ->withBody($contents, 'application/octet-stream')
                ->put($sessionUrl);

            if ($response->status() === 429) {
                throw $this->rateLimitExceptionFrom($response);
            }

            if (! $response->successful()) {
                $this->logProviderFailure('uploadMedia.push', $response);

                throw $this->apiException($response);
            }

            $offset = (int) ($response->header('next_offset') ?? 0);
        }
    }

    protected function mediaContents(MediaAsset $media): string
    {
        $path = (string) ($this->attribute($media, 'storage_path') ?? '');

        if ($path === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'media_missing',
                'This video could not be uploaded because the file is missing from storage.',
                'The media asset has no storage_path.',
                false,
                'Re-upload the video, then republish the post.',
            ));
        }

        $disk = (string) (($this->attribute($media, 'storage_disk')) ?: config('socialhub.media.disk', 'local'));
        $contents = Storage::disk($disk)->get($path);

        if ($contents === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'media_empty',
                'This video could not be uploaded because the stored file is empty.',
                sprintf('The stored media file "%s" on disk "%s" is empty.', $path, $disk),
                false,
                'Re-upload the video, then republish the post.',
            ));
        }

        return $contents;
    }

    protected function containerIdFrom(Response $response, string $operation): string
    {
        $this->assertSuccessful($response, $operation);

        $body = (array) $response->json();
        $containerId = (string) ($body['id'] ?? $body['ig-container-id'] ?? '');

        if ($containerId === '') {
            throw $this->apiException($response, UserFacingError::make(
                'container_missing',
                'Instagram accepted the media but returned no container, so nothing could be published.',
                'The /media container response carried no id or ig-container-id.',
                true,
                'Retry the publish; if it keeps failing, check that the media URL is publicly reachable over HTTPS.',
            ));
        }

        return $containerId;
    }

    // ------------------------------------------------------------- Insights

    /**
     * @param  array<string, string>  $query
     * @return list<MetricSample>
     */
    protected function insightSamples(SocialAccount $account, string $url, array $query): array
    {
        if (($query['metric'] ?? '') === '') {
            return [];
        }

        $response = $this->graph($account)->get($url, $query);

        // A professional account with no history for this window answers with an
        // error rather than an empty series; that is legitimately zero data, not
        // a failure worth surfacing.
        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $samples = [];

        foreach ((array) ((array) $response->json())['data'] ?? [] as $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $name = InstagramConfig::INSIGHT_METRICS[(string) ($metric['name'] ?? '')] ?? null;

            if ($name === null) {
                continue;
            }

            foreach ((array) ($metric['values'] ?? []) as $value) {
                if (! is_array($value)) {
                    continue;
                }

                $samples[] = new MetricSample(
                    metric: $name,
                    value: (float) ($value['value'] ?? 0),
                    periodStart: isset($value['end_time']) ? (string) $value['end_time'] : null,
                    periodEnd: isset($value['end_time']) ? (string) $value['end_time'] : null,
                    granularity: 'day',
                );
            }
        }

        return $samples;
    }

    /**
     * @return list<string>
     */
    protected function graphMetricsFor(AnalyticsQuery $query): array
    {
        $wanted = $query->metrics !== [] ? $query->metrics : array_values(InstagramConfig::INSIGHT_METRICS);

        $metrics = [];

        foreach (InstagramConfig::INSIGHT_METRICS as $graphMetric => $normalised) {
            if (in_array($normalised, $wanted, true)) {
                $metrics[] = $graphMetric;
            }
        }

        return $metrics;
    }

    // -------------------------------------------------------------- Helpers

    /**
     * Instagram fetches media itself from a public HTTPS URL whose domain is
     * verified in the Meta app settings, so a stored file path is never
     * acceptable here.
     */
    protected function publicMediaUrl(PublishPayload $publish, int $index, string $kind): string
    {
        $url = $this->mediaUrlOf($publish->options, $index)
            ?? ($this->isPublicUrl($publish->mediaIds[$index] ?? null) ? $publish->mediaIds[$index] : null);

        if ($url === null) {
            throw $this->unsupportedContentFormat(
                'publicMediaUrl',
                sprintf(
                    'Instagram fetches the %s itself from a public HTTPS URL whose domain is verified in the Meta app settings. Add that URL to the variant media; Instagram cannot read our storage.',
                    $kind,
                ),
            );
        }

        return $url;
    }

    protected function isPublicUrl(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'https://');
    }

    protected function isVideoUrl(string $value): bool
    {
        return $this->isPublicUrl($value) && preg_match('/\.(mp4|mov|webm)(\?|$)/i', $value) === 1;
    }

    protected function requestedMediaType(MediaAsset $media): string
    {
        $requested = (string) (($this->attribute($media, 'metadata')['media_type'] ?? '') ?: '');

        return in_array($requested, ['REELS', 'STORIES', 'VIDEO'], true) ? $requested : 'VIDEO';
    }

    protected function igId(SocialAccount $account): string
    {
        return (string) $this->accountIdOf($account);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    protected function profileFrom(array $data, SocialAccount $account, array $raw = []): AccountProfile
    {
        $username = isset($data['username']) ? (string) $data['username'] : null;

        return new AccountProfile(
            provider: self::KEY,
            providerAccountId: (string) ($data['id'] ?? ''),
            username: $username,
            displayName: isset($data['name']) ? (string) $data['name'] : null,
            avatarUrl: $data['profile_picture_url'] ?? null,
            accountType: 'business',
            profileUrl: $username !== null ? 'https://www.instagram.com/'.$username : null,
            followerCount: isset($data['followers_count']) ? (int) $data['followers_count'] : null,
            grantedScopes: $this->grantedScopesOf($account),
            raw: $raw === [] ? $data : $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function contentTypeFrom(array $data): string
    {
        if (($data['media_product_type'] ?? null) === 'feed') {
            return PublishPayload::TYPE_CAROUSEL;
        }

        return match ((string) ($data['media_type'] ?? '')) {
            'VIDEO' => PublishPayload::TYPE_VIDEO,
            'CAROUSEL_ALBUM' => PublishPayload::TYPE_CAROUSEL,
            default => PublishPayload::TYPE_IMAGE,
        };
    }
}
