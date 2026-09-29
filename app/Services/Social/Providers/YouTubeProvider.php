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
use App\Services\Social\Config\YouTubeConfig;
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
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * YouTube Data API v3 plus the YouTube Analytics API.
 *
 * Two API surfaces are involved and they are not interchangeable. Lifetime
 * view, like, and comment counts come from the `statistics` part of a `video`
 * resource; watch time and average view duration exist only in the Analytics
 * API, so a batch that promises watch time genuinely calls `reports.query`.
 *
 * Uploads use the documented resumable protocol: an init POST returns a session
 * URI, the bytes are PUT to that URI, and a final GET reports the video's
 * processing status. `uploadMedia()` performs the byte transfer and returns the
 * video id, which `createPost()` then writes metadata against.
 */
class YouTubeProvider implements SocialProviderInterface
{
    use GuardsProviderOperations;
    use HasUnsignedWebhooks;
    use MapsProviderErrors;
    use PaginatesCursor;
    use PublishesFromPostVariant;
    use ReadsConnectedAccount;
    use RespectsRateLimitHeaders;
    use UsesOAuth2AuthorizationCode;

    public const KEY = 'youtube';

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
        return 'YouTube';
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        $overrides = (array) config('socialhub.providers.youtube.capabilities_override', []);

        return PlatformCapabilities::youtube()->withOverrides($overrides);
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
                $this->offlineAuthorizeUrl($request->withState($request->state ?? $this->newState())),
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
            // Google issues a refresh token only on the first authorization. If
            // it is gone the connection is unrecoverable and pretending
            // otherwise would silently fail on every later publish.
            throw new TokenRevokedException(self::KEY, UserFacingError::make(
                'refresh_token_missing',
                'This YouTube connection can no longer be refreshed. Reconnect the channel to continue publishing.',
                'Google issued no refresh token for this connection, so no automatic refresh is possible.',
                false,
                'Reconnect the channel from the Social Accounts page.',
            ));
        }

        return $this->refreshAccessToken($stored->refreshToken, $this->oauthHttp());
    }

    // -------------------------------------------------------------- Account

    public function getAccount(SocialAccount $account): AccountProfile
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(YouTubeConfig::API_BASE.'/channels', [
            'part' => 'snippet,contentDetails,statistics,brandingSettings',
            'mine' => 'true',
        ]);

        $this->assertSuccessful($response, 'getAccount');

        $items = $this->itemsFrom((array) $response->json(), 'items');

        return $this->profileFrom((array) ($items[0] ?? []), $account);
    }

    /**
     * A Google account authorises against exactly one channel, so this returns
     * the single channel rather than pretending a brand has several.
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

        $response = $this->api($account)->get(YouTubeConfig::API_BASE.'/channels', [
            'part' => 'statistics,snippet',
            'mine' => 'true',
        ]);

        $this->assertSuccessful($response, 'getFollowers');

        $items = $this->itemsFrom((array) $response->json(), 'items');
        $channel = (array) ($items[0] ?? []);
        $statistics = (array) ($channel['statistics'] ?? []);

        // hiddenSubscriberCount makes the count unavailable to the owner too;
        // reporting zero there would be a lie, so the field is left null.
        $hidden = (bool) ($statistics['hiddenSubscriberCount'] ?? false);
        $subscribers = isset($statistics['subscriberCount']) ? (int) $statistics['subscriberCount'] : null;

        return new FollowerStats(
            provider: self::KEY,
            providerAccountId: (string) ($channel['id'] ?? $this->channelId($account)),
            total: $subscribers ?? 0,
            breakdown: $hidden || $subscribers === null ? [] : ['subscribers' => $subscribers],
            asOf: new DateTimeImmutable,
            raw: $statistics,
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
        $videoId = $this->videoIdFor($publish);

        $body = $this->videoResource($publish);
        $parts = implode(',', array_keys($body));

        $response = $this->api($account)->post(YouTubeConfig::API_BASE.'/videos', [
            'part' => $parts,
            'id' => $videoId,
        ] + $body);

        $this->assertSuccessful($response, 'createPost');

        $data = (array) $response->json();
        $snippet = (array) ($data['snippet'] ?? []);
        $status = (array) ($data['status'] ?? []);

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: (string) ($data['id'] ?? $videoId),
            permalink: $this->permalink((string) ($data['id'] ?? $videoId)),
            text: isset($snippet['description']) ? (string) $snippet['description'] : null,
            contentType: $publish->contentType(),
            attachedMediaIds: [$videoId],
            publishedAt: $this->publishedAt($status, $snippet),
            raw: $data,
        );
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        return $this->createPost($account, ['payload' => $this->payloadFromVariant($variant)]);
    }

    /**
     * Runs the resumable upload protocol and returns the YouTube video id.
     *
     * Step 1 opens a session with `uploadType=resumable`, step 2 PUTs the file
     * to the returned session URI, step 3 confirms the bytes landed. A
     * `processing` state at step 3 is normal — YouTube transcodes after the
     * upload — so it is reported rather than treated as a failure.
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        $this->assertUnsupported('videoPublishing');
        $this->assertConfigured();

        $this->assertWithinByteLimit(
            (int) ($this->attribute($media, 'file_size') ?? 0),
            YouTubeConfig::MAX_UPLOAD_BYTES,
            'Upload a smaller file; YouTube accepts up to 2 GB per video through the resumable protocol.',
        );

        $session = $this->openUploadSession($account, $media);
        $this->transferUpload($account, $media, $session);

        $status = $this->confirmUpload($account, $session);

        if (($status['state'] ?? '') === 'failed') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'upload_failed',
                'YouTube rejected this video file. Nothing was published.',
                sprintf('The resumable upload session reported state=failed with reason "%s".', $status['reason'] ?? 'unspecified'),
                false,
                'Check the file is a supported container and codec, then re-upload.',
            ));
        }

        return $session['video_id'];
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        $this->assertUnsupported('deletePost');
        $this->assertConfigured();

        // videos.delete carries the id as a query parameter, not a body field.
        $response = $this->api($account)->delete(
            YouTubeConfig::API_BASE.'/videos?'.http_build_query(['id' => $providerPostId]),
        );

        $this->assertSuccessful($response, 'deletePost');

        return $response->successful();
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(YouTubeConfig::API_BASE.'/videos', [
            'part' => 'snippet,statistics,status,contentDetails',
            'id' => $providerPostId,
        ]);

        $this->assertSuccessful($response, 'getPost');

        $items = $this->itemsFrom((array) $response->json(), 'items');
        $data = (array) ($items[0] ?? []);

        $snippet = (array) ($data['snippet'] ?? []);
        $status = (array) ($data['status'] ?? []);

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: (string) ($data['id'] ?? $providerPostId),
            permalink: $this->permalink($providerPostId),
            text: isset($snippet['description']) ? (string) $snippet['description'] : null,
            contentType: PublishPayload::TYPE_VIDEO,
            publishedAt: $this->publishedAt($status, $snippet),
            createdAt: $this->readDate($snippet, 'publishedAt'),
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
                'YouTube comments are readable per video only. Pass the id of the specific video whose comments you want.',
            );
        }

        $limit = (int) config('socialhub.pagination.default_page_size', 50);

        $pages = $this->paginate(
            fn (?string $pageToken): Response => $this->api($account)->get(YouTubeConfig::API_BASE.'/commentThreads', array_filter([
                'part' => 'snippet',
                'videoId' => $providerPostId,
                'maxResults' => min(100, $limit),
                'pageToken' => $pageToken,
            ], static fn (mixed $value): bool => $value !== null)),
            fn (array $body): ?string => $this->googlePageTokenFrom($body),
        );

        $comments = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($page, 'items')) as $thread) {
            $comments[] = $this->hydrateComment($thread, $providerPostId);
        }

        return $comments;
    }

    /**
     * A reply is a `comment` whose `parentId` names the comment it answers.
     * `commentThreads.insert` cannot express that, so replies go to
     * `comments.insert`.
     */
    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        $this->assertUnsupported('commentReplies');
        $this->assertConfigured();

        $response = $this->api($account)->post(YouTubeConfig::API_BASE.'/comments', [
            'part' => 'snippet',
            'body' => [
                'snippet' => [
                    'textOriginal' => $body,
                    'parentId' => $comment->providerCommentId,
                ],
            ],
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
            $samples = $this->videoStatisticsSamples($account, (string) $postId, $query->metrics);

            if ($samples !== []) {
                $postMetrics[(string) $postId] = $samples;
            }
        }

        $watchTime = $this->analyticsReportSamples($account, $query, [
            'watch_time_minutes',
            'avg_view_duration_seconds',
        ]);

        return new AnalyticsBatch(
            provider: self::KEY,
            periodStart: $query->from,
            periodEnd: $query->to,
            accountMetrics: $watchTime,
            postMetrics: $postMetrics,
            granularity: $query->granularity,
        );
    }

    // ------------------------------------------------------------ Lifecycle

    public function disconnect(SocialAccount $account): void
    {
        $this->assertConfigured();

        $revoke = (string) (config('socialhub.providers.youtube.oauth.revoke_url') ?: '');

        if ($revoke === '') {
            return;
        }

        try {
            $this->oauthHttp()->postForm($revoke, ['token' => $this->tokens->resolve($account, self::KEY)->accessToken]);
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
        return YouTubeConfig::errorMap();
    }

    protected function extractErrorCode(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;

        if (is_array($error)) {
            if (is_string($error['status'] ?? null) && $error['status'] !== '') {
                return $error['status'];
            }

            if (is_string($error['reason'] ?? null) && $error['reason'] !== '') {
                return $error['reason'];
            }
        }

        $reason = $error['errors'][0]['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Google's 429 carries no usable Retry-After. Quota resets daily, so a short
     * delay would only burn more of the same exhausted quota.
     */
    protected function fallbackRetryAfter(): int
    {
        return 900;
    }

    protected function throwPaginationFailure(Response $response): ProviderApiException
    {
        return $this->buildApiException($response);
    }

    protected function assertConfigured(): void
    {
        $credentials = (array) config('socialhub.providers.youtube.credentials', []);

        $missing = [];

        foreach (['client_id' => 'YOUTUBE_CLIENT_ID', 'client_secret' => 'YOUTUBE_CLIENT_SECRET'] as $key => $envKey) {
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

    /**
     * Google's authorization endpoint needs `access_type=offline` or it never
     * issues a refresh token and the connection dies after an hour.
     */
    protected function offlineAuthorizeUrl(AuthRequest $request): string
    {
        return $this->oauthAuthorizeUrl($request).'&access_type=offline&include_granted_scopes=true';
    }

    // ------------------------------------------------------------ Publishing

    /**
     * @return array<string, mixed>
     */
    protected function videoResource(PublishPayload $publish): array
    {
        $title = trim($publish->title ?? '') !== '' ? (string) $publish->title : $this->defaultTitle($publish->text);

        $snippet = [
            'title' => $title,
            'description' => $publish->text,
        ];

        if ($publish->hashtags !== []) {
            $snippet['tags'] = $publish->hashtags;
        }

        $status = [
            'privacyStatus' => (string) ($publish->options['privacy_status'] ?? YouTubeConfig::DEFAULT_PRIVACY),
        ];

        if ($publish->isScheduled()) {
            // A scheduled video must be private; YouTube rejects publishAt on
            // any other privacy status.
            $status['privacyStatus'] = 'private';
            $status['publishAt'] = $this->scheduledAtFor($publish)->format(DateTimeInterface::ATOM);
        }

        return ['snippet' => $snippet, 'status' => $status];
    }

    protected function scheduledAtFor(PublishPayload $publish): DateTimeInterface
    {
        $scheduledAt = $publish->scheduledAt ?? new DateTimeImmutable;

        if ($scheduledAt->getTimestamp() < time() + YouTubeConfig::MIN_SCHEDULE_LEAD_MINUTES * 60) {
            throw $this->unsupportedContentFormat(
                'scheduling',
                sprintf(
                    'YouTube only accepts a scheduled publish at least %d minutes in the future. Move the time later or publish this post immediately instead.',
                    YouTubeConfig::MIN_SCHEDULE_LEAD_MINUTES,
                ),
            );
        }

        return $scheduledAt;
    }

    /**
     * YouTube publishes video, so the variant must name the video the upload
     * step produced.
     */
    protected function videoIdFor(PublishPayload $publish): string
    {
        $videoId = (string) ($publish->mediaIds[0] ?? '');

        if ($videoId === '') {
            throw $this->unsupportedContentFormat(
                'videoPublishing',
                'YouTube publishes video only. Upload the video first so this variant has a video id, or remove YouTube from this post.',
            );
        }

        return $videoId;
    }

    protected function defaultTitle(string $text): string
    {
        $firstLine = strtok(trim($text), "\n");

        return trim($firstLine === false ? 'Untitled upload' : $firstLine) ?: 'Untitled upload';
    }

    /**
     * @return array{video_id: string, session_uri: string}
     */
    protected function openUploadSession(SocialAccount $account, MediaAsset $media): array
    {
        $init = Http::withToken($this->tokens->resolve($account, self::KEY)->accessToken)
            ->timeout((int) config('socialhub.publishing.timeout', 30))
            ->withHeaders([
                'X-Upload-Content-Type' => (string) ($this->attribute($media, 'mime_type') ?: 'video/mp4'),
                'X-Upload-Content-Length' => (string) ((int) ($this->attribute($media, 'file_size') ?? 0)),
            ])
            ->post(YouTubeConfig::UPLOAD_BASE.'/videos?uploadType='.YouTubeConfig::RESUMABLE_UPLOAD_TYPE.'&part=snippet,status', [
                'snippet' => ['title' => $this->defaultTitle((string) ($this->attribute($media, 'filename') ?? ''))],
                'status' => ['privacyStatus' => 'private'],
            ]);

        if (! $init->successful()) {
            $this->logProviderFailure('uploadMedia.init', $init);

            throw $this->buildApiException($init);
        }

        $sessionUri = (string) ($init->header('Location') ?? $init->header('X-GUploader-UploadID') ?? '');

        if ($sessionUri === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'upload_session_missing',
                'YouTube did not open a resumable upload session for this video.',
                'The videos.insert resumable init returned no Location header.',
                true,
                'Retry the upload; if it keeps failing, check that youtube.upload is granted for this channel.',
            ));
        }

        return ['video_id' => (string) ((array) $init->json())['id'] ?? '', 'session_uri' => $sessionUri];
    }

    protected function transferUpload(SocialAccount $account, MediaAsset $media, array $session): void
    {
        $contents = $this->mediaContents($media);

        $response = Http::withToken($this->tokens->resolve($account, self::KEY)->accessToken)
            ->timeout((int) config('socialhub.publishing.insights_timeout', 60))
            ->withBody($contents, 'application/octet-stream')
            ->withHeaders([
                'Content-Length' => (string) strlen($contents),
                'Content-Range' => sprintf('bytes 0-%d/%d', max(0, strlen($contents) - 1), strlen($contents)),
            ])
            ->put($session['session_uri']);

        if (! $response->successful()) {
            $this->logProviderFailure('uploadMedia.transfer', $response);

            throw $this->buildApiException($response);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function confirmUpload(SocialAccount $account, array $session): array
    {
        $response = $this->api($account)->get(YouTubeConfig::API_BASE.'/videos', [
            'part' => 'processingDetails,status',
            'id' => $session['video_id'],
        ]);

        if (! $response->successful()) {
            $this->logProviderFailure('uploadMedia.confirm', $response);

            throw $this->buildApiException($response);
        }

        $items = $this->itemsFrom((array) $response->json(), 'items');
        $data = (array) ($items[0] ?? []);

        $processing = (array) ($data['processingDetails'] ?? []);

        return [
            'state' => (string) ($processing['processingStatus'] ?? 'succeeded'),
            'reason' => (string) ($processing['processingFailureReason'] ?? ''),
            'video_id' => (string) ($data['id'] ?? $session['video_id']),
        ];
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

    // ------------------------------------------------------------- Insights

    /**
     * @param  list<string>  $only
     * @return list<MetricSample>
     */
    protected function videoStatisticsSamples(SocialAccount $account, string $videoId, array $only = []): array
    {
        $response = $this->api($account)->get(YouTubeConfig::API_BASE.'/videos', [
            'part' => 'statistics',
            'id' => $videoId,
        ]);

        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $items = $this->itemsFrom((array) $response->json(), 'items');
        $statistics = (array) (((array) ($items[0] ?? []))['statistics'] ?? []);

        $samples = [];

        foreach (YouTubeConfig::STATISTICS_METRICS as $field => $normalised) {
            if ($only !== [] && ! in_array($normalised, $only, true)) {
                continue;
            }

            if (! isset($statistics[$field])) {
                continue;
            }

            $samples[] = new MetricSample(
                metric: $normalised,
                value: (float) $statistics[$field],
                granularity: 'lifetime',
            );
        }

        return $samples;
    }

    /**
     * Watch time and average view duration exist only in the Analytics API, so
     * these are fetched through a real `reports.query` call.
     *
     * @param  list<string>  $only
     * @return list<MetricSample>
     */
    protected function analyticsReportSamples(SocialAccount $account, AnalyticsQuery $query, array $only): array
    {
        $metrics = [];

        foreach (YouTubeConfig::ANALYTICS_METRICS as $normalised => $definition) {
            if (in_array($normalised, $only, true)) {
                $metrics[] = $definition['metric'];
            }
        }

        if ($metrics === []) {
            return [];
        }

        $response = $this->api($account)->get(YouTubeConfig::ANALYTICS_BASE, [
            'ids' => 'channel=='.$this->channelId($account),
            'startDate' => $query->from->format('Y-m-d'),
            'endDate' => $query->to->format('Y-m-d'),
            'metrics' => implode(',', $metrics),
            'dimension' => 'day',
        ]);

        // A channel that has never had analytics enabled answers 403; that is a
        // missing data set, not a failed sync.
        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $normalised = $this->analyticsMetricNames($metrics);
        $samples = [];

        foreach ((array) (((array) $response->json())['rows'] ?? []) as $row) {
            $values = array_values((array) $row);

            $date = (string) array_shift($values);

            foreach ($values as $index => $value) {
                $name = $normalised[$index] ?? null;

                if ($name === null) {
                    continue;
                }

                $samples[] = new MetricSample(
                    metric: $name,
                    value: (float) $value,
                    periodStart: $date,
                    periodEnd: $date,
                    granularity: 'day',
                );
            }
        }

        return $samples;
    }

    /**
     * @param  list<string>  $metrics
     * @return list<string>
     */
    protected function analyticsMetricNames(array $metrics): array
    {
        $names = [];

        foreach (YouTubeConfig::ANALYTICS_METRICS as $normalised => $definition) {
            if (in_array($definition['metric'], $metrics, true)) {
                $names[] = $normalised;
            }
        }

        return $names;
    }

    // -------------------------------------------------------------- Helpers

    /**
     * @param  array<string, mixed>  $body
     */
    protected function googlePageTokenFrom(array $body): ?string
    {
        $token = $body['nextPageToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $snippet
     * @param  array<string, mixed>  $status
     */
    protected function publishedAt(array $status, array $snippet): ?DateTimeInterface
    {
        $publishAt = $this->readDate($status, 'publishAt');
        $publishedAt = $this->readDate($snippet, 'publishedAt');

        return $publishAt ?? $publishedAt;
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    protected function profileFrom(array $channel, SocialAccount $account): AccountProfile
    {
        $snippet = (array) ($channel['snippet'] ?? []);
        $statistics = (array) ($channel['statistics'] ?? []);

        $username = isset($snippet['customUrl']) ? (string) $snippet['customUrl'] : null;

        return new AccountProfile(
            provider: self::KEY,
            providerAccountId: (string) ($channel['id'] ?? ''),
            username: $username,
            displayName: isset($snippet['title']) ? (string) $snippet['title'] : null,
            avatarUrl: $snippet['thumbnails']['default']['url'] ?? null,
            accountType: 'channel',
            profileUrl: $username !== null ? 'https://www.youtube.com/'.$username : null,
            followerCount: isset($statistics['subscriberCount']) ? (int) $statistics['subscriberCount'] : null,
            grantedScopes: $this->grantedScopesOf($account),
            raw: $channel,
        );
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    protected function hydrateComment(array $thread, string $videoId): ProviderComment
    {
        $snippet = (array) ((array) $thread['snippet'] ?? [])['topLevelComment'] ?? [];
        $inner = (array) ($snippet['snippet'] ?? []);

        // The Data API puts this on the comment itself; older payloads put it
        // on the thread. Both are real shapes, so both are accepted.
        $replies = (int) ($snippet['totalReplyCount'] ?? $inner['totalReplyCount'] ?? 0);

        return new ProviderComment(
            provider: self::KEY,
            providerCommentId: (string) ($snippet['id'] ?? ''),
            providerPostId: $videoId,
            content: (string) ($inner['textOriginal'] ?? $inner['textDisplay'] ?? ''),
            authorProviderId: isset($snippet['authorChannelId']['value']) ? (string) $snippet['authorChannelId']['value'] : null,
            likeCount: (int) ($inner['likeCount'] ?? 0),
            replyCount: $replies,
            createdAt: $this->readDate($inner, 'publishedAt'),
            raw: $thread,
        );
    }

    protected function channelId(SocialAccount $account): string
    {
        return (string) $this->accountIdOf($account);
    }

    protected function permalink(string $videoId): ?string
    {
        return $videoId === '' ? null : 'https://www.youtube.com/watch?v='.$videoId;
    }
}
