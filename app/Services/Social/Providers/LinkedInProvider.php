<?php

declare(strict_types=1);

namespace App\Services\Social\Providers;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Config\LinkedInConfig;
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

/**
 * LinkedIn Posts API, reached through LinkedIn OIDC.
 *
 * Two author identities exist and they are not interchangeable. A member post
 * needs `w_member_social` and an author URN of `urn:li:person:{id}`. An
 * organization post needs `w_organization_social`, a role on the Page, and an
 * author URN of `urn:li:organization:{id}`. Everything that only works for an
 * organization — nested comment replies, organic analytics, video views — is
 * gated on which identity the connected account actually is, because reporting
 * a member's data through an organization endpoint would be wrong, not merely
 * unavailable.
 *
 * LinkedIn has no delete endpoint, so `deletePost()` refuses rather than
 * pretending.
 */
class LinkedInProvider implements SocialProviderInterface
{
    use GuardsProviderOperations;
    use HasUnsignedWebhooks;
    use MapsProviderErrors;
    use PaginatesCursor;
    use PublishesFromPostVariant;
    use ReadsConnectedAccount;
    use RespectsRateLimitHeaders;
    use UsesOAuth2AuthorizationCode;

    public const KEY = 'linkedin';

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
        return 'LinkedIn';
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        $overrides = (array) config('socialhub.providers.linkedin.capabilities_override', []);

        return PlatformCapabilities::linkedin()->withOverrides($overrides);
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

        $response = $this->api($account)->get(LinkedInConfig::USER_INFO, [
            'projection' => '(id,sub,name,given_name,family_name,picture,email)',
        ]);

        $this->assertSuccessful($response, 'getAccount');

        $data = (array) $response->json();

        return new AccountProfile(
            provider: self::KEY,
            providerAccountId: $this->personUrn($data),
            displayName: isset($data['name']) ? (string) $data['name'] : null,
            avatarUrl: $data['picture'] ?? null,
            accountType: 'member',
            grantedScopes: $this->grantedScopesOf($account),
            raw: $data,
        );
    }

    /**
     * Company Pages the connected member administers, from the organization ACL
     * finder. The `organization` field on an ACL is the Page URN to post as.
     *
     * @return list<AccountProfile>
     */
    public function getPages(SocialAccount $account): array
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(LinkedInConfig::API_BASE.LinkedInConfig::ORGANIZATION_ACLS, [
            'q' => 'roleAssignee',
            'roleAssignee' => 'me',
            'role' => 'ADMINISTRATOR,DIRECT_SPONSORED_CONTENT_POSTER,CONTENT_ADMIN',
            'state' => 'APPROVED',
            'count' => LinkedInConfig::FINDER_PAGE_SIZE,
        ]);

        $this->assertSuccessful($response, 'getPages');

        $profiles = [];

        foreach ((array) ((array) $response->json())['elements'] ?? [] as $element) {
            $urn = (string) ((array) ($element['organization'] ?? ''))['urn'] ?? '';

            if ($urn === '') {
                continue;
            }

            $profiles[] = new AccountProfile(
                provider: self::KEY,
                providerAccountId: $urn,
                displayName: $this->organizationNameFromUrn($urn),
                accountType: 'organization',
                profileUrl: $urn,
                grantedScopes: $this->grantedScopesOf($account),
                raw: ['role' => $element['role'] ?? null, 'organization' => $urn],
            );
        }

        return $profiles;
    }

    /**
     * LinkedIn exposes no follower count outside the gated Community Management
     * partner program, so there is nothing to report honestly here.
     */
    public function getFollowers(SocialAccount $account): FollowerStats
    {
        $this->assertUnsupported('followers');
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
            // LinkedIn has no scheduled publishing endpoint.
            $this->assertUnsupported('scheduling');
        }

        $author = $this->authorUrn($account);
        $type = $publish->contentType();

        $this->assertUnsupported($this->capabilityFor($type));

        $content = $this->contentFor($account, $publish, $type);

        $response = $this->api($account)->post(LinkedInConfig::API_BASE.LinkedInConfig::POSTS, [
            'author' => $author,
            'commentary' => $this->commentary($publish),
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'content' => $content,
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ]);

        $this->assertSuccessful($response, 'createPost');

        // Rest.li returns the new share URN only in this header.
        $shareUrn = (string) ($response->header('x-restli-id') ?: $response->header('x-linkedin-id') ?? '');

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: $shareUrn,
            permalink: $shareUrn === '' ? null : $this->permalinkFrom($shareUrn),
            text: $publish->text,
            contentType: $type,
            attachedMediaIds: $publish->mediaIds,
            publishedAt: new DateTimeImmutable,
            raw: ['share_urn' => $shareUrn, 'author' => $author],
        );
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        return $this->createPost($account, ['payload' => $this->payloadFromVariant($variant)]);
    }

    /**
     * Registers the media with LinkedIn and returns the URN the Posts API takes
     * in its `content` block.
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        $this->assertConfigured();

        $mimeType = (string) ($this->attribute($media, 'mime_type') ?: 'image/jpeg');
        $isVideo = str_starts_with($mimeType, 'video/');

        $this->assertUnsupported($isVideo ? 'videoPublishing' : 'imagePublishing');

        $initialize = $this->api($account)->post(LinkedInConfig::API_BASE.'/images?action=initializeUpload', array_filter([
            'initializeUploadRequest' => ['owner' => $this->authorUrn($account)],
        ]));

        $this->assertSuccessful($initialize, 'uploadMedia.initialize');

        $uploadUrl = (string) ((array) ((array) $initialize->json())['value'] ?? [])['uploadUrl'] ?? '';
        $imageUrn = (string) ((array) ((array) $initialize->json())['value'] ?? [])['image'] ?? '';

        if ($uploadUrl === '' || $imageUrn === '') {
            throw $this->buildApiException($initialize, UserFacingError::make(
                'upload_url_missing',
                'LinkedIn did not return an upload URL for this image.',
                'The images?action=initializeUpload response carried no uploadUrl or image URN.',
                true,
                'Retry the upload; if it keeps failing, check the app is approved for the Images API.',
            ));
        }

        $this->pushImageBytes($account, $media, $uploadUrl);

        return $imageUrn;
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        $this->assertUnsupported('deletePost');
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(
            LinkedInConfig::API_BASE.LinkedInConfig::POSTS.'/'.rawurlencode($providerPostId),
        );

        $this->assertSuccessful($response, 'getPost');

        return $this->postFrom((array) $response->json(), $providerPostId);
    }

    // ------------------------------------------------------------- Comments

    /**
     * Reads the social actions on a share. This is organization-only: a member
     * profile has no equivalent read scope.
     *
     * @return list<ProviderComment>
     */
    public function getComments(SocialAccount $account, ?string $providerPostId = null): array
    {
        $this->assertUnsupported('comments');
        $this->assertConfigured();
        $this->assertOrganizationAuthor($account, 'comments');

        if ($providerPostId === null || $providerPostId === '') {
            throw $this->unsupportedContentFormat(
                'comments',
                'LinkedIn comments are readable per share only. Pass the share or UGC post URN whose comments you want.',
            );
        }

        $limit = (int) config('socialhub.pagination.default_page_size', 50);

        $pages = $this->paginate(
            fn (?string $start): Response => $this->api($account)->get(
                LinkedInConfig::API_BASE.LinkedInConfig::SOCIAL_ACTIONS.'/'.rawurlencode($providerPostId).'/comments',
                array_filter([
                    'start' => (int) $start,
                    'count' => min(200, $limit),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ),
            fn (array $body): ?string => $this->restliStartFrom($body),
        );

        $comments = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($page, 'elements')) as $element) {
            $comments[] = $this->hydrateComment($element, $providerPostId);
        }

        return $comments;
    }

    /**
     * Nested replies carry `parentComment`, which LinkedIn only accepts on an
     * organization-authored share. A member profile can write no reply at all,
     * and saying so is more useful than a 403 from the API.
     */
    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void
    {
        $this->assertUnsupported('commentReplies');
        $this->assertConfigured();
        $this->assertOrganizationAuthor($account, 'commentReplies');

        $response = $this->api($account)->post(
            LinkedInConfig::API_BASE.LinkedInConfig::SOCIAL_ACTIONS.'/'.rawurlencode($comment->providerPostId).'/comments',
            [
                'actor' => $this->authorUrn($account),
                'message' => ['text' => $body],
                'parentComment' => $comment->providerCommentId,
            ],
        );

        $this->assertSuccessful($response, 'replyToComment');
    }

    // ------------------------------------------------------------- Insights

    /**
     * Organization share statistics plus the live social-action summary. Both
     * endpoints are organization-only, so a member account reports nothing
     * rather than an invented zero.
     */
    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        $this->assertUnsupported('analytics');
        $this->assertConfigured();
        $this->assertOrganizationAuthor($account, 'analytics');

        $organization = $this->authorUrn($account);

        $postMetrics = [];

        foreach ($query->postIds as $postId) {
            $samples = $this->shareStatistics($account, $organization, (string) $postId);

            if ($samples !== []) {
                $postMetrics[(string) $postId] = $samples;
            }
        }

        return new AnalyticsBatch(
            provider: self::KEY,
            periodStart: $query->from,
            periodEnd: $query->to,
            accountMetrics: $this->aggregateShareStatistics($account, $organization, $query),
            postMetrics: $postMetrics,
            granularity: $query->granularity,
        );
    }

    // ------------------------------------------------------------ Lifecycle

    public function disconnect(SocialAccount $account): void
    {
        $this->assertConfigured();

        $revoke = (string) (config('socialhub.providers.linkedin.oauth.revoke_url') ?: '');

        if ($revoke === '') {
            return;
        }

        try {
            $this->oauthHttp()->postForm($revoke, [
                'token' => $this->tokens->resolve($account, self::KEY)->accessToken,
            ]);
        } catch (\Throwable $e) {
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
        return LinkedInConfig::errorMap();
    }

    protected function extractErrorCode(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $code = $body['code'] ?? $body['status'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    protected function fallbackRetryAfter(): int
    {
        // LinkedIn's member API allowance is per day.
        return 3600;
    }

    /**
     * @throws ProviderNotConfiguredException
     */
    protected function assertConfigured(): void
    {
        $credentials = (array) config('socialhub.providers.linkedin.credentials', []);

        $missing = [];

        foreach (['client_id' => 'LINKEDIN_CLIENT_ID', 'client_secret' => 'LINKEDIN_CLIENT_SECRET'] as $key => $envKey) {
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

    /**
     * Every Marketing API call needs the dated version and the Rest.li protocol
     * version headers; without them LinkedIn answers 403.
     */
    protected function api(SocialAccount $account): ProviderHttpClient
    {
        return $this->http
            ->withAccessToken($this->tokens->resolve($account, self::KEY)->accessToken)
            ->withTenant($this->tenantIdOf($account))
            ->withHeader('LinkedIn-Version', (string) config('socialhub.providers.linkedin.oauth.api_version', '202601'))
            ->withHeader('X-Restli-Protocol-Version', LinkedInConfig::RESTLI_PROTOCOL_VERSION);
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

    // ------------------------------------------------------------ Publishing

    /**
     * The URN a post is authored as. An organization account uses its own
     * `urn:li:organization:{id}`; a member account resolves its person URN.
     */
    protected function authorUrn(SocialAccount $account): string
    {
        $stored = (string) $this->accountIdOf($account);

        if (str_starts_with($stored, 'urn:li:')) {
            return $stored;
        }

        return 'urn:li:person:'.$stored;
    }

    /**
     * @throws UnsupportedCapabilityException
     */
    protected function assertOrganizationAuthor(SocialAccount $account, string $capability): void
    {
        if (str_starts_with($this->authorUrn($account), 'urn:li:organization')) {
            return;
        }

        throw new UnsupportedCapabilityException(
            $capability,
            self::KEY,
            UserFacingError::make(
                'unsupported_capability',
                sprintf('This LinkedIn connection is a member profile, and %s is only available on a company Page. Connect the Page to use it.', $this->describe($capability)),
                sprintf('The linked account is %s but capability "%s" requires an organization URN.', $this->authorUrn($account), $capability),
                false,
                'Reconnect the company Page instead of the member profile to enable this feature.',
            ),
        );
    }

    protected function describe(string $capability): string
    {
        return match ($capability) {
            'comments' => 'reading comments',
            'commentReplies' => 'replying to comments',
            'analytics' => 'post analytics',
            default => $this->platformName().' '.$capability,
        };
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

    /**
     * @return array<string, mixed>
     */
    protected function contentFor(SocialAccount $account, PublishPayload $publish, string $type): array
    {
        if ($type === PublishPayload::TYPE_CAROUSEL) {
            $this->assertUnsupported('carouselPublishing');

            return ['multiImage' => ['images' => $this->imageUrnsFor($publish)]];
        }

        if ($type === PublishPayload::TYPE_VIDEO) {
            return [
                'video' => [
                    'videoId' => (string) ($publish->mediaIds[0] ?? ''),
                    'title' => ['text' => $publish->title ?? ''],
                ],
            ];
        }

        if ($type === PublishPayload::TYPE_IMAGE) {
            return ['media' => ['id' => (string) ($publish->mediaIds[0] ?? ''), 'title' => ['text' => $publish->title ?? '']]];
        }

        if ($publish->link !== null && $publish->link !== '') {
            $this->assertUnsupported('linkPosts');

            return ['article' => [
                'source' => $publish->link,
                'title' => $publish->title ?? $publish->text,
                'description' => ['text' => $publish->text],
            ]];
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function imageUrnsFor(PublishPayload $publish): array
    {
        $images = [];

        foreach ($publish->mediaIds as $mediaId) {
            $images[] = ['id' => $mediaId, 'title' => ['text' => $publish->title ?? '']];
        }

        return $images;
    }

    /**
     * LinkedIn rejects a post whose commentary is empty or a bare URL, so the
     * check happens before the call rather than after a 422.
     */
    protected function commentary(PublishPayload $publish): string
    {
        $text = trim($publish->text);

        if (strlen($text) >= LinkedInConfig::MIN_COMMENTARY_LENGTH) {
            return $text;
        }

        if ($publish->link !== null && $publish->link !== '') {
            return $publish->link;
        }

        throw $this->unsupportedContentFormat(
            'textPublishing',
            'LinkedIn rejects a post with no text. Add commentary to this variant, or remove LinkedIn from the post.',
        );
    }

    protected function pushImageBytes(SocialAccount $account, MediaAsset $media, string $uploadUrl): void
    {
        $path = (string) ($this->attribute($media, 'storage_path') ?? '');

        if ($path === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'media_missing',
                'This image could not be uploaded because the file is missing from storage.',
                'The media asset has no storage_path.',
                false,
                'Re-upload the image, then republish the post.',
            ));
        }

        $disk = (string) (($this->attribute($media, 'storage_disk')) ?: config('socialhub.media.disk', 'local'));
        $contents = \Illuminate\Support\Facades\Storage::disk($disk)->get($path);

        if ($contents === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'media_empty',
                'This image could not be uploaded because the stored file is empty.',
                sprintf('The stored media file "%s" on disk "%s" is empty.', $path, $disk),
                false,
                'Re-upload the image, then republish the post.',
            ));
        }

        $response = \Illuminate\Support\Facades\Http::withToken($this->tokens->resolve($account, self::KEY)->accessToken)
            ->timeout((int) config('socialhub.publishing.timeout', 30))
            ->withBody($contents, 'application/octet-stream')
            ->put($uploadUrl);

        if (! $response->successful()) {
            $this->logProviderFailure('uploadMedia.push', $response);

            throw $this->buildApiException($response);
        }
    }

    // ------------------------------------------------------------- Insights

    /**
     * @return list<MetricSample>
     */
    protected function shareStatistics(SocialAccount $account, string $organization, string $shareUrn): array
    {
        $response = $this->api($account)->get(LinkedInConfig::API_BASE.LinkedInConfig::ORGANIZATION_SHARE_STATISTICS, [
            'q' => 'organizationalEntity',
            'organizationalEntity' => $organization,
            'shares' => $this->asListParam($shareUrn),
        ]);

        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $samples = [];

        foreach ((array) ((array) $response->json())['elements'] ?? [] as $element) {
            $samples = array_merge($samples, $this->statisticsSamples((array) ($element['totalShareStatistics'] ?? [])));
        }

        return $samples;
    }

    /**
     * @return list<MetricSample>
     */
    protected function aggregateShareStatistics(SocialAccount $account, string $organization, AnalyticsQuery $query): array
    {
        $response = $this->api($account)->get(LinkedInConfig::API_BASE.LinkedInConfig::ORGANIZATION_SHARE_STATISTICS, [
            'q' => 'organizationalEntity',
            'organizationalEntity' => $organization,
            'timeIntervals' => json_encode([
                'timeGranularityType' => 'DAY',
                'timeRange' => [
                    'start' => $query->from->getTimestamp() * 1000,
                    'end' => $query->to->getTimestamp() * 1000,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        $samples = [];

        foreach ((array) ((array) $response->json())['elements'] ?? [] as $element) {
            foreach ((array) ($element['timeIntervals'] ?? []) as $interval) {
                $statistics = (array) ((array) $interval)['totalShareStatistics'] ?? [];
                $day = (int) ((((array) $interval)['timeRange'] ?? [])['start'] ?? 0);

                foreach ($this->statisticsSamples($statistics) as $sample) {
                    $date = $day > 0 ? date('Y-m-d', intdiv($day, 1000)) : null;

                    $samples[] = new MetricSample(
                        metric: $sample->metric,
                        value: $sample->value,
                        periodStart: $date,
                        periodEnd: $date,
                        granularity: 'day',
                    );
                }
            }
        }

        return $samples;
    }

    /**
     * @param  array<string, mixed>  $statistics
     * @return list<MetricSample>
     */
    protected function statisticsSamples(array $statistics): array
    {
        $map = [
            'impressionCount' => 'impressions',
            'uniqueImpressionsCount' => 'impressions',
            'likeCount' => 'likes',
            'commentCount' => 'comments',
            'shareCount' => 'shares',
            'clickCount' => 'clicks',
        ];

        $samples = [];

        foreach ($map as $field => $normalised) {
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

    // -------------------------------------------------------------- Helpers

    /**
     * @param  array<string, mixed>  $body
     */
    protected function restliStartFrom(array $body): ?string
    {
        $start = $body['start'] ?? null;

        return is_int($start) ? (string) $start : null;
    }

    /**
     * @param  array<string, mixed>  $userInfo
     */
    protected function personUrn(array $userInfo): string
    {
        $id = (string) ($userInfo['sub'] ?? $userInfo['id'] ?? '');

        return $id === '' ? '' : 'urn:li:person:'.$id;
    }

    protected function organizationNameFromUrn(string $urn): string
    {
        $id = str_contains($urn, ':') ? substr($urn, strrpos($urn, ':') + 1) : $urn;

        return 'LinkedIn Page '.$id;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    protected function postFrom(array $post, string $fallbackId): ProviderPost
    {
        $commentary = (array) ($post['commentary'] ?? []);

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: (string) ($post['id'] ?? $fallbackId),
            text: isset($commentary['text']) ? (string) $commentary['text'] : null,
            contentType: $this->contentTypeFrom($post),
            publishedAt: $this->readDate($post, 'created'),
            createdAt: $this->readDate($post, 'created'),
            raw: $post,
        );
    }

    /**
     * @param  array<string, mixed>  $post
     */
    protected function contentTypeFrom(array $post): string
    {
        $content = (array) ($post['content'] ?? []);

        return match (true) {
            isset($content['media']) => PublishPayload::TYPE_IMAGE,
            isset($content['video']) => PublishPayload::TYPE_VIDEO,
            isset($content['multiImage']) => PublishPayload::TYPE_CAROUSEL,
            isset($content['article']) => PublishPayload::TYPE_LINK,
            default => PublishPayload::TYPE_TEXT,
        };
    }

    /**
     * @param  array<string, mixed>  $element
     */
    protected function hydrateComment(array $element, string $shareUrn): ProviderComment
    {
        $message = (array) ($element['message'] ?? []);
        $actor = (string) ($element['actor'] ?? '');

        return new ProviderComment(
            provider: self::KEY,
            providerCommentId: (string) ($element['id'] ?? ''),
            providerPostId: $shareUrn,
            content: (string) ($message['text'] ?? ''),
            parentProviderCommentId: isset($element['parentComment']) ? (string) $element['parentComment'] : null,
            authorProviderId: $actor === '' ? null : $actor,
            createdAt: $this->readDate($element, 'created'),
            raw: $element,
        );
    }

    protected function asListParam(string $value): string
    {
        return 'List('.$value.')';
    }

    protected function permalinkFrom(string $shareUrn): string
    {
        $id = str_contains($shareUrn, ':') ? substr($shareUrn, strrpos($shareUrn, ':') + 1) : $shareUrn;

        return sprintf('https://www.linkedin.com/feed/update/%s', $id);
    }
}
