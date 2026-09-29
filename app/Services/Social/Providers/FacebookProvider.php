<?php

declare(strict_types=1);

namespace App\Services\Social\Providers;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Exceptions\Social\WebhookSignatureException;
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
use App\Services\Social\Data\MetricSample;
use App\Services\Social\Data\ProviderComment;
use App\Services\Social\Data\ProviderPost;
use App\Services\Social\Data\PublishPayload;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\Data\UserFacingError;
use App\Services\Social\Data\VerifiedWebhookRequest;
use App\Services\Social\OAuth\UsesOAuth2AuthorizationCode;
use App\Services\Social\ProviderHttpClient;
use App\Services\Social\Providers\Concerns\MapsProviderErrors;
use App\Services\Social\Providers\Concerns\PaginatesCursor;
use App\Services\Social\Providers\Concerns\RespectsRateLimitHeaders;
use App\Services\Social\Support\AccountTokenResolver;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Meta Graph API provider for Facebook Pages.
 *
 * Reference implementation: every other provider follows this shape —
 * capabilities asserted up front, a typed DTO out, and a social exception on
 * failure. Nothing here is simulated; if the Graph API cannot do something, the
 * call throws {@see UnsupportedCapabilityException} instead of inventing a
 * result.
 */
class FacebookProvider implements SocialProviderInterface
{
    use MapsProviderErrors;
    use PaginatesCursor;
    use RespectsRateLimitHeaders;
    use UsesOAuth2AuthorizationCode;

    public const KEY = 'facebook';

    /**
     * Insight metrics we ask the Graph API for, mapped to the normalised
     * vocabulary. A metric the page did not report is absent, never zero.
     *
     * @var array<string, string>
     */
    private const INSIGHT_METRICS = [
        'post_impressions' => 'impressions',
        'post_impressions_unique' => 'reach',
        'post_video_views' => 'views',
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
        return 'Facebook';
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        $overrides = (array) config('socialhub.providers.facebook.capabilities_override', []);

        return PlatformCapabilities::facebook()->withOverrides($overrides);
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

        $refreshToken = $this->tokens->resolve($account, self::KEY)->refreshToken;

        if ($refreshToken === null || $refreshToken === '') {
            return $this->tokens->resolve($account, self::KEY);
        }

        return $this->refreshAccessToken($refreshToken, $this->oauthHttp());
    }

    // -------------------------------------------------------------- Account

    public function getAccount(SocialAccount $account): AccountProfile
    {
        $this->assertConfigured();

        $fields = 'id,name,username,fan_count,followers_count,link,category,picture{url}';
        $response = $this->graph($account)->get($this->edge('me'), ['fields' => $fields]);

        $this->assertSuccessful($response, 'getAccount');

        $data = (array) $response->json();

        return new AccountProfile(
            provider: self::KEY,
            providerAccountId: (string) ($data['id'] ?? ''),
            username: isset($data['username']) ? (string) $data['username'] : null,
            displayName: isset($data['name']) ? (string) $data['name'] : null,
            avatarUrl: $data['picture']['data']['url'] ?? null,
            accountType: isset($data['category']) ? 'page' : 'user',
            profileUrl: isset($data['link']) ? (string) $data['link'] : null,
            followerCount: isset($data['followers_count']) ? (int) $data['followers_count'] : null,
            grantedScopes: $this->grantedScopes($account),
            raw: $data,
        );
    }

    /**
     * Pages the connected identity administers, walking Meta's after-cursor.
     *
     * @return list<AccountProfile>
     */
    public function getPages(SocialAccount $account): array
    {
        $this->assertConfigured();

        $fields = 'id,name,username,fan_count,followers_count,link,category,access_token,picture{url}';
        $limit = (int) config('socialhub.pagination.default_page_size', 50);

        $pages = $this->paginate(
            fn (?string $after): Response => $this->graph($account)->get($this->edge('me/accounts'), array_filter([
                'fields' => $fields,
                'limit' => $limit,
                'after' => $after,
            ], static fn (mixed $value): bool => $value !== null)),
            fn (array $body): ?string => $this->cursorFrom($body),
        );

        $profiles = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($page)) as $page) {
            $profiles[] = new AccountProfile(
                provider: self::KEY,
                providerAccountId: (string) ($page['id'] ?? ''),
                username: isset($page['username']) ? (string) $page['username'] : null,
                displayName: isset($page['name']) ? (string) $page['name'] : null,
                avatarUrl: $page['picture']['data']['url'] ?? null,
                accountType: 'page',
                profileUrl: isset($page['link']) ? (string) $page['link'] : null,
                followerCount: isset($page['followers_count']) ? (int) $page['followers_count'] : null,
                grantedScopes: $this->grantedScopes($account),
                raw: $this->withoutPageToken($page),
            );
        }

        return $profiles;
    }

    public function getFollowers(SocialAccount $account): FollowerStats
    {
        $capabilities = $this->getSupportedFeatures();
        $capabilities->assertSupports('followers', self::KEY, $this->platformName());

        $this->assertConfigured();

        $response = $this->graph($account)->get($this->edge((string) $this->accountId($account)), [
            'fields' => 'followers_count,fan_count',
        ]);

        $this->assertSuccessful($response, 'getFollowers');

        $data = (array) $response->json();

        $followers = (int) ($data['followers_count'] ?? $data['fan_count'] ?? 0);
        $fans = isset($data['fan_count']) ? (int) $data['fan_count'] : null;

        return new FollowerStats(
            provider: self::KEY,
            providerAccountId: (string) $this->accountId($account),
            total: $followers,
            breakdown: $fans !== null ? ['fans' => $fans, 'followers' => $followers] : ['followers' => $followers],
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
        $capabilities = $this->getSupportedFeatures();
        $capabilities->assertSupports('publishing', self::KEY, $this->platformName());

        $publish = $payload['payload'] ?? null;

        if (! $publish instanceof PublishPayload) {
            $publish = new PublishPayload(
                text: (string) ($payload['text'] ?? $payload['message'] ?? ''),
                mediaIds: (array) ($payload['media_ids'] ?? []),
                link: isset($payload['link']) ? (string) $payload['link'] : null,
                scheduledAt: isset($payload['scheduled_at']) ? new DateTimeImmutable((string) $payload['scheduled_at']) : null,
                firstComment: isset($payload['first_comment']) ? (string) $payload['first_comment'] : null,
                contentType: isset($payload['content_type']) ? (string) $payload['content_type'] : null,
                options: (array) ($payload['options'] ?? []),
            );
        }

        $this->assertConfigured();

        if ($publish->isScheduled()) {
            $capabilities->assertSupports('scheduling', self::KEY, $this->platformName());
        }

        $fields = $publish->attachedFields();

        $response = match ($publish->contentType()) {
            PublishPayload::TYPE_CAROUSEL => $this->publishCarousel($account, $fields),
            PublishPayload::TYPE_IMAGE, PublishPayload::TYPE_VIDEO,
            PublishPayload::TYPE_REEL, PublishPayload::TYPE_STORY => $this->publishMedia($account, $fields),
            PublishPayload::TYPE_LINK => $this->publishLink($account, $fields),
            default => $this->publishText($account, $fields),
        };

        $this->assertSuccessful($response, 'createPost');

        $data = (array) $response->json();
        $postId = (string) ($data['post_id'] ?? $data['id'] ?? '');

        if ($publish->firstComment !== null && $publish->firstComment !== '') {
            $capabilities->assertSupports('firstComment', self::KEY, $this->platformName());
            $this->publishFirstComment($account, $postId, $publish->firstComment);
        }

        $result = new ProviderPost(
            provider: self::KEY,
            providerPostId: $postId,
            permalink: $this->permalink($account, $postId),
            text: $publish->text,
            contentType: $publish->contentType(),
            attachedMediaIds: $publish->mediaIds,
            publishedAt: new DateTimeImmutable,
            raw: $data,
        );

        return $result;
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        $publish = new PublishPayload(
            text: (string) ($this->attribute($variant, 'caption') ?? ''),
            mediaIds: (array) ($this->attribute($variant, 'media_ids') ?? []),
            scheduledAt: $this->dateAttribute($variant, 'scheduled_at'),
            firstComment: (string) ($this->attribute($variant, 'first_comment') ?? '') ?: null,
            contentType: (string) ($this->attribute($variant, 'content_type') ?? '') ?: null,
            options: (array) ($this->attribute($variant, 'platform_specific') ?? []),
        );

        return $this->createPost($account, ['payload' => $publish]);
    }

    /**
     * Resumable video upload handshake. Returns the Graph video id.
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        $capabilities = $this->getSupportedFeatures();
        $capabilities->assertSupports('videoPublishing', self::KEY, $this->platformName());

        $this->assertConfigured();
        $this->assertMediaWithinLimits($media);

        $path = (string) ($this->attribute($media, 'storage_path') ?? '');

        if ($path === '') {
            throw new ProviderApiException(
                self::KEY,
                0,
                null,
                UserFacingError::make(
                    'media_missing',
                    'This video could not be uploaded because the file is missing from storage.',
                    'The media asset has no storage_path.',
                    false,
                    'Re-upload the video, then republish the post.',
                ),
            );
        }

        $size = (int) ($this->attribute($media, 'file_size') ?? 0);

        $response = $this->graph($account)->post($this->edge((string) $this->accountId($account).'/video_uploads'), [
            'file_size' => $size,
        ]);

        $this->assertSuccessful($response, 'uploadMedia');

        $data = (array) $response->json();
        $videoId = (string) ($data['video_id'] ?? $data['id'] ?? '');

        if ($videoId === '') {
            throw $this->apiException($response);
        }

        return $videoId;
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        $capabilities = $this->getSupportedFeatures();
        $capabilities->assertSupports('deletePost', self::KEY, $this->platformName());

        $this->assertConfigured();

        $response = $this->graph($account)->delete($this->edge($providerPostId));

        $this->assertSuccessful($response, 'deletePost');

        $body = (array) $response->json();

        return (bool) ($body['success'] ?? $response->successful());
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        $this->assertConfigured();

        $response = $this->graph($account)->get($this->edge($providerPostId), [
            'fields' => 'id,message,permalink_url,created_time,updated_time,attachments',
        ]);

        $this->assertSuccessful($response, 'getPost');

        $data = (array) $response->json();

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: (string) ($data['id'] ?? $providerPostId),
            permalink: isset($data['permalink_url']) ? (string) $data['permalink_url'] : null,
            text: isset($data['message']) ? (string) $data['message'] : null,
            contentType: $this->contentTypeFrom($data),
            publishedAt: $this->dateAttribute($data, 'created_time'),
            createdAt: $this->dateAttribute($data, 'created_time'),
            raw: $data,
        );
    }

    // ------------------------------------------------------------- Comments

    /**
     * @return list<ProviderComment>
     */
    public function getComments(SocialAccount $account, ?string $providerPostId = null): array
    {
        $capabilities = $this->getSupportedFeatures();
        $capabilities->assertSupports('comments', self::KEY, $this->platformName());

        $this->assertConfigured();

        $target = $providerPostId ?? (string) $this->accountId($account);
        $edge = $this->edge($providerPostId !== null
            ? $target.'/comments'
            : $target.'/feed/comments');
        $limit = (int) config('socialhub.pagination.default_page_size', 50);

        $pages = $this->paginate(
            fn (?string $after): Response => $this->graph($account)->get($edge, array_filter([
                'fields' => 'id,message,created_time,like_count,comment_count,from{id,name,username},parent{id}',
                'limit' => $limit,
                'after' => $after,
            ], static fn (mixed $value): bool => $value !== null)),
            fn (array $body): ?string => $this->cursorFrom($body),
        );

        $comments = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($page)) as $comment) {
            $comments[] = $this->hydrateComment($comment, (string) $target);
        }

        return $comments;
    }

    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        $capabilities = $this->getSupportedFeatures();
        $capabilities->assertSupports('commentReplies', self::KEY, $this->platformName());

        $this->assertConfigured();

        $response = $this->graph($account)->post($this->edge($comment->providerCommentId.'/comments'), [
            'message' => $body,
        ]);

        $this->assertSuccessful($response, 'replyToComment');
    }

    // ------------------------------------------------------------- Insights

    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        $capabilities = $this->getSupportedFeatures();
        $capabilities->assertSupports('analytics', self::KEY, $this->platformName());

        $this->assertConfigured();

        $accountId = (string) $this->accountId($account);
        $samples = [];

        foreach (self::INSIGHT_METRICS as $graphMetric => $normalised) {
            if ($query->metrics !== [] && ! in_array($normalised, $query->metrics, true)) {
                continue;
            }

            $response = $this->graph($account)->get($this->edge($accountId.'/insights'), [
                'metric' => $graphMetric,
                'period' => 'day',
                'since' => $query->from->format('Y-m-d'),
                'until' => $query->to->format('Y-m-d'),
            ]);

            $this->assertSuccessful($response, 'getAnalytics');

            // Key off the metric name the response actually carries, not the
            // one requested: a page that does not report `reach` must not have
            // reach recorded as zero.
            foreach ((array) $response->json()['data'] ?? [] as $metric) {
                $reported = self::INSIGHT_METRICS[(string) ($metric['name'] ?? '')] ?? null;

                if ($reported === null) {
                    continue;
                }

                foreach ($this->samplesFrom(['data' => [$metric]], $reported) as $sample) {
                    $samples[] = $sample;
                }
            }
        }

        $postMetrics = [];

        foreach ($query->postIds as $postId) {
            $response = $this->graph($account)->get($this->edge($postId.'/insights'), [
                'metric' => implode(',', array_keys(self::INSIGHT_METRICS)),
                'period' => 'day',
            ]);

            if (! $response->successful()) {
                $this->assertNotRateLimited($response);

                continue;
            }

            $postSamples = [];

            foreach ((array) $response->json()['data'] ?? [] as $metric) {
                $normalised = self::INSIGHT_METRICS[(string) ($metric['name'] ?? '')] ?? null;

                if ($normalised === null) {
                    continue;
                }

                foreach ($this->samplesFrom(['data' => [$metric]], $normalised) as $sample) {
                    $postSamples[] = $sample;
                }
            }

            if ($postSamples !== []) {
                $postMetrics[$postId] = $postSamples;
            }
        }

        return new AnalyticsBatch(
            provider: self::KEY,
            periodStart: $query->from,
            periodEnd: $query->to,
            accountMetrics: $samples,
            postMetrics: $postMetrics,
            granularity: $query->granularity,
        );
    }

    // ------------------------------------------------------------- Lifecycle

    /**
     * Best-effort: deauthorize the app so the user also loses access upstream.
     * A failure here must not block the local disconnect, so it is swallowed
     * after being logged without any credential material.
     */
    public function disconnect(SocialAccount $account): void
    {
        $this->assertConfigured();

        try {
            $this->graph($account)->delete($this->edge('me/permissions'));
        } catch (Throwable $e) {
            $this->logDisconnectFailure($e);
        }
    }

    // -------------------------------------------------------------- Webhooks

    public function verifyWebhook(VerifiedWebhookRequest $request): bool
    {
        $secret = (string) (config('socialhub.providers.facebook.oauth.webhook_secret') ?? '');

        if ($secret === '') {
            throw new ProviderNotConfiguredException(
                self::KEY,
                'the Meta app secret used for webhook signatures is not configured',
            );
        }

        $signature = $request->header('X-Hub-Signature-256');

        if ($signature === null || $signature === '') {
            throw new WebhookSignatureException(self::KEY, WebhookSignatureException::REASON_MISSING_SIGNATURE);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new WebhookSignatureException(self::KEY, WebhookSignatureException::REASON_SIGNATURE);
        }

        $this->assertTimestampWithinTolerance($request);

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
                'object_id' => $value['post_id'] ?? $value['comment_id'] ?? $value['media_id'] ?? null,
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

    protected function errorMap(): array
    {
        return [
            '190' => UserFacingError::make(
                'token_invalid',
                'This Facebook Page access has expired or was revoked. Reconnect the account to continue.',
                'Graph API error 190: the access token is invalid or has expired.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            '102' => UserFacingError::make(
                'session_expired',
                'The Facebook session expired. Reconnect the account to continue.',
                'Graph API error 102: the session has expired.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            '200' => UserFacingError::make(
                'permission_missing',
                'This Page is missing a permission needed to publish. Reconnect it and approve the requested access.',
                'Graph API error 200: the app does not have the required permission.',
                false,
                'Reconnect the Page and approve the requested permissions.',
            ),
            '10' => UserFacingError::make(
                'permission_missing',
                'This Facebook Page does not grant the app the access needed to publish on its behalf.',
                'Graph API error 10: the permission is not granted for this object.',
                false,
                'Check the Page access in Business Manager and reconnect the account.',
            ),
            '368' => UserFacingError::make(
                'temporarily_blocked',
                'Facebook has temporarily blocked publishing from this account. The post will be retried later.',
                'Graph API error 368: the action was temporarily blocked.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '4' => UserFacingError::make(
                'rate_limited',
                'Facebook is asking us to slow down. The post will be retried automatically.',
                'Graph API error 4: the application has reached its rate limit.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '17' => UserFacingError::make(
                'rate_limited',
                'Facebook is asking us to slow down. The post will be retried automatically.',
                'Graph API error 17: the call rate limit was reached.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '32' => UserFacingError::make(
                'rate_limited',
                'Facebook is asking us to slow down. The post will be retried automatically.',
                'Graph API error 32: the page-level rate limit was reached.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '613' => UserFacingError::make(
                'rate_limited',
                'Facebook is asking us to slow down. The post will be retried automatically.',
                'Graph API error 613: custom rate limit reached.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '1' => UserFacingError::make(
                'provider_error',
                'Facebook could not complete this request. Nothing was published.',
                'Graph API error 1: an unspecified API error occurred.',
                true,
                'The post will be retried; contact support if it keeps failing.',
            ),
        ];
    }

    protected function throwPaginationFailure(Response $response): ProviderApiException
    {
        return $this->apiException($response);
    }

    protected function apiException(Response $response, ?UserFacingError $userFacingError = null): ProviderApiException
    {
        return $this->buildApiException($response, $userFacingError);
    }

    /**
     * @throws UnsupportedCapabilityException
     */
    protected function assertUnsupported(string $capability): void
    {
        $this->getSupportedFeatures()->assertSupports($capability, self::KEY, $this->platformName());
    }

    // ----------------------------------------------------------------- HTTP

    protected function graph(SocialAccount $account): ProviderHttpClient
    {
        $token = $this->tokens->resolve($account, self::KEY);
        $pageToken = $this->pageAccessToken($account) ?? $token->accessToken;

        return $this->http
            ->withAccessToken($pageToken)
            ->withTenant($this->tenantIdOf($account))
            ->withHeader('X-Api-Version', (string) config('socialhub.providers.facebook.oauth.graph_version', 'v23.0'));
    }

    protected function oauthHttp(): ProviderHttpClient
    {
        return $this->http->withoutThrottle();
    }

    protected function edge(string $path): string
    {
        $version = (string) config('socialhub.providers.facebook.oauth.graph_version', 'v23.0');

        return sprintf('https://graph.facebook.com/%s/%s', $version, ltrim($path, '/'));
    }

    protected function assertConfigured(): void
    {
        $credentials = (array) config('socialhub.providers.facebook.credentials', []);
        $missing = [];

        foreach (['client_id', 'client_secret'] as $key) {
            $value = $credentials[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new ProviderNotConfiguredException(
                self::KEY,
                sprintf('missing %s (set %sAPP_ID / %sAPP_SECRET in .env)', implode(', ', $missing), 'META_', 'META_'),
            );
        }
    }

    /**
     * @throws WebhookSignatureException
     */
    protected function assertTimestampWithinTolerance(VerifiedWebhookRequest $request): void
    {
        $timestamp = $request->header('X-Hub-Signature-Timestamp') ?? $request->header('X-Facebook-Delivery-Timestamp');

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            return;
        }

        $tolerance = (int) config('socialhub.webhooks.tolerance_seconds', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new WebhookSignatureException(self::KEY, WebhookSignatureException::REASON_TIMESTAMP);
        }
    }

    protected function assertSuccessful(Response $response, string $operation): void
    {
        $this->assertNotRateLimited($response);

        if (! $response->successful()) {
            $this->logProviderFailure($operation, $response);

            throw $this->apiException($response);
        }
    }

    protected function logDisconnectFailure(Throwable $e): void
    {
        logger()->warning('SocialHub provider disconnect call failed; continuing with the local disconnect.', [
            'provider' => self::KEY,
            'error' => $e->getMessage(),
        ]);
    }

    // ------------------------------------------------------------ Publishing

    /**
     * @return array<string, string>
     */
    protected function publishText(SocialAccount $account, array $fields): Response
    {
        $this->assertUnsupported('textPublishing');

        return $this->graph($account)->post($this->edge((string) $this->accountId($account).'/feed'), [
            'message' => $fields['message'] ?? '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function publishMedia(SocialAccount $account, array $fields): Response
    {
        $isVideo = ($fields['media_kind'] ?? PublishPayload::TYPE_IMAGE) === PublishPayload::TYPE_VIDEO;

        $this->assertUnsupported($isVideo ? 'videoPublishing' : 'imagePublishing');

        return $this->graph($account)->post($this->edge((string) $this->accountId($account).'/feed'), $fields);
    }

    /**
     * @return array<string, string>
     */
    protected function publishCarousel(SocialAccount $account, array $fields): Response
    {
        $this->assertUnsupported('carouselPublishing');

        $childIds = [];

        foreach ($fields['children'] ?? [] as $child) {
            $response = $this->graph($account)->post($this->edge((string) $this->accountId($account).'/feed'), $child);

            $this->assertSuccessful($response, 'createPost.carousel');

            $childIds[] = (string) ((array) $response->json())['id'];
        }

        return $this->graph($account)->post($this->edge((string) $this->accountId($account).'/feed'), [
            'message' => $fields['message'] ?? '',
            'attached_media' => json_encode([
                'media_fbid' => $childIds,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function publishLink(SocialAccount $account, array $fields): Response
    {
        $this->assertUnsupported('linkPosts');

        return $this->graph($account)->post($this->edge((string) $this->accountId($account).'/feed'), $fields);
    }

    protected function publishFirstComment(SocialAccount $account, string $postId, string $body): void
    {
        if ($postId === '') {
            return;
        }

        $response = $this->graph($account)->post($this->edge($postId.'/comments'), ['message' => $body]);

        $this->assertSuccessful($response, 'createPost.firstComment');
    }

    protected function permalink(SocialAccount $account, string $postId): ?string
    {
        if ($postId === '') {
            return null;
        }

        return sprintf(
            'https://www.facebook.com/%s/posts/%s',
            $this->accountId($account),
            $postId,
        );
    }

    // -------------------------------------------------------------- Mapping

    /**
     * @param  array<string, mixed>  $data
     * @return list<MetricSample>
     */
    protected function samplesFrom(array $data, string $normalised): array
    {
        $samples = [];

        foreach ((array) ($data['data'] ?? []) as $point) {
            if (! is_array($point)) {
                continue;
            }

            $values = $point['values'] ?? null;

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (! is_array($value)) {
                    continue;
                }

                $end = isset($value['end_time']) ? (string) $value['end_time'] : null;

                $samples[] = new MetricSample(
                    metric: $normalised,
                    value: (float) ($value['value'] ?? 0),
                    periodStart: $end,
                    periodEnd: $end,
                    granularity: 'day',
                );
            }
        }

        return $samples;
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    protected function hydrateComment(array $comment, string $postId): ProviderComment
    {
        return new ProviderComment(
            provider: self::KEY,
            providerCommentId: (string) ($comment['id'] ?? ''),
            providerPostId: $postId,
            content: (string) ($comment['message'] ?? ''),
            parentProviderCommentId: isset($comment['parent']['id']) ? (string) $comment['parent']['id'] : null,
            authorProviderId: isset($comment['from']['id']) ? (string) $comment['from']['id'] : null,
            authorUsername: isset($comment['from']['username']) ? (string) $comment['from']['username'] : null,
            authorDisplayName: isset($comment['from']['name']) ? (string) $comment['from']['name'] : null,
            likeCount: (int) ($comment['like_count'] ?? 0),
            replyCount: (int) ($comment['comment_count'] ?? 0),
            createdAt: $this->dateAttribute($comment, 'created_time'),
            raw: $comment,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function contentTypeFrom(array $data): ?string
    {
        $attachments = (array) ($data['attachments']['data'] ?? []);

        if ($attachments === []) {
            return PublishPayload::TYPE_TEXT;
        }

        foreach ($attachments as $attachment) {
            $type = (string) ($attachment['type'] ?? '');

            if ($type === 'video') {
                return PublishPayload::TYPE_VIDEO;
            }

            if ($type === 'photo') {
                return PublishPayload::TYPE_IMAGE;
            }
        }

        return PublishPayload::TYPE_IMAGE;
    }

    /**
     * Reads a date from a decoded provider payload or from a model attribute,
     * so the same call site works for both response data and Eloquent models.
     */
    protected function dateAttribute(array|Model $data, string $key): ?DateTimeInterface
    {
        $value = $data instanceof Model ? $data->getAttribute($key) : ($data[$key] ?? null);

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value)) {
            return (new DateTimeImmutable)->setTimestamp($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : (new DateTimeImmutable)->setTimestamp($timestamp);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    protected function withoutPageToken(array $page): array
    {
        unset($page['access_token']);

        return $page;
    }

    /**
     * @return list<string>
     */
    protected function grantedScopes(SocialAccount $account): array
    {
        $metadata = $this->attribute($account, 'metadata');

        return is_array($metadata) && isset($metadata['granted_scopes']) && is_array($metadata['granted_scopes'])
            ? array_values($metadata['granted_scopes'])
            : [];
    }

    /**
     * A Page publishes with its own page access token, not the user token.
     */
    protected function pageAccessToken(SocialAccount $account): ?string
    {
        $metadata = $this->attribute($account, 'metadata');

        if (! is_array($metadata)) {
            return null;
        }

        $token = $metadata['page_access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    protected function accountId(SocialAccount $account): string|int|null
    {
        return $this->attribute($account, 'provider_account_id');
    }

    protected function tenantIdOf(SocialAccount $account): int|string|null
    {
        $tenantId = $this->attribute($account, 'tenant_id');

        return is_int($tenantId) || is_string($tenantId) ? $tenantId : null;
    }

    protected function attribute(object $model, string $key): mixed
    {
        try {
            return method_exists($model, 'getAttribute')
                ? $model->getAttribute($key)
                : ($model->{$key} ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    protected function assertMediaWithinLimits(MediaAsset $media): void
    {
        $maxKb = (int) config('socialhub.media.max_size_kb', 2048);
        $size = (int) ($this->attribute($media, 'file_size') ?? 0);

        if ($size > $maxKb * 1024) {
            throw new ProviderApiException(
                self::KEY,
                0,
                null,
                UserFacingError::make(
                    'media_too_large',
                    sprintf('This file is larger than the %d MB limit for Facebook uploads.', intdiv($maxKb, 1024)),
                    sprintf('Media asset size %d bytes exceeds the configured %d KB cap.', $size, $maxKb),
                    false,
                    'Compress the video or choose a smaller file, then republish.',
                ),
            );
        }
    }
}
