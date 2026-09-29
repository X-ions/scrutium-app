<?php

declare(strict_types=1);

namespace App\Services\Social\Providers;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Config\PinterestConfig;
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

/**
 * Pinterest API v5.
 *
 * A Pin is always a board plus a `media_source`. Images can be sent inline as
 * `image_base64`; a video Pin needs a `media_id` from a prior `/media`
 * registration, which is what `uploadMedia()` returns.
 *
 * Pinterest has no comment API at all, and `PinCreate` has no scheduled publish
 * field, so `getComments()`, `replyToComment()`, and any scheduled publish all
 * refuse rather than return a plausible-looking empty result. The v5 PinCreate
 * body genuinely has no such field, so a "scheduled" Pin is a publish held on our
 * side and released at the time.
 */
class PinterestProvider implements SocialProviderInterface
{
    use GuardsProviderOperations;
    use HasUnsignedWebhooks;
    use MapsProviderErrors;
    use PaginatesCursor;
    use PublishesFromPostVariant;
    use ReadsConnectedAccount;
    use RespectsRateLimitHeaders;
    use UsesOAuth2AuthorizationCode;

    public const KEY = 'pinterest';

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
        return 'Pinterest';
    }

    public function getSupportedFeatures(): ProviderCapabilities
    {
        $overrides = (array) config('socialhub.providers.pinterest.capabilities_override', []);

        return PlatformCapabilities::pinterest()->withOverrides($overrides);
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

        $response = $this->api($account)->get(PinterestConfig::API_BASE.PinterestConfig::ACCOUNT);

        $this->assertSuccessful($response, 'getAccount');

        $data = (array) $response->json();
        $username = isset($data['username']) ? (string) $data['username'] : null;

        return new AccountProfile(
            provider: self::KEY,
            providerAccountId: (string) ($data['id'] ?? ''),
            username: $username,
            displayName: isset($data['business_account_name']) && $data['business_account_name'] !== null
                ? (string) $data['business_account_name']
                : $username,
            avatarUrl: $data['profile_image'] ?? null,
            accountType: isset($data['business_account_name']) && $data['business_account_name'] !== null ? 'business' : 'user',
            profileUrl: $username !== null ? 'https://www.pinterest.com/'.$username : null,
            followerCount: isset($data['follower_count']) ? (int) $data['follower_count'] : null,
            grantedScopes: $this->grantedScopesOf($account),
            raw: $data,
        );
    }

    /**
     * Pinterest publishes to boards, not to sub-profiles, so the board list is
     * the set of publish targets.
     *
     * @return list<AccountProfile>
     */
    public function getPages(SocialAccount $account): array
    {
        $this->assertConfigured();

        $limit = min(PinterestConfig::MAX_BOARD_PINS_PAGE, (int) config('socialhub.pagination.default_page_size', 50));

        $pages = $this->paginate(
            fn (?string $after): Response => $this->api($account)->get(PinterestConfig::API_BASE.PinterestConfig::BOARDS, array_filter([
                'page_size' => $limit,
                'bookmark' => $after,
            ], static fn (mixed $value): bool => $value !== null)),
            fn (array $body): ?string => $this->pinterestBookmarkFrom($body),
        );

        $boards = [];

        foreach ($this->collectItems($pages, fn (array $page): array => $this->itemsFrom($page, 'items')) as $board) {
            $boards[] = new AccountProfile(
                provider: self::KEY,
                providerAccountId: (string) ($board['id'] ?? ''),
                displayName: isset($board['name']) ? (string) $board['name'] : null,
                accountType: 'board',
                profileUrl: $board['url'] ?? null,
                followerCount: isset($board['follower_count']) ? (int) $board['follower_count'] : null,
                grantedScopes: $this->grantedScopesOf($account),
                raw: $board,
            );
        }

        return $boards;
    }

    public function getFollowers(SocialAccount $account): FollowerStats
    {
        $this->assertUnsupported('followers');
        $this->assertConfigured();

        $response = $this->api($account)->get(PinterestConfig::API_BASE.PinterestConfig::ACCOUNT);

        $this->assertSuccessful($response, 'getFollowers');

        $data = (array) $response->json();
        $followers = (int) ($data['follower_count'] ?? 0);

        return new FollowerStats(
            provider: self::KEY,
            providerAccountId: (string) ($data['id'] ?? $this->accountIdOf($account)),
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
            // PinCreate has no scheduled publish field.
            $this->assertUnsupported('scheduling');
        }

        $type = $publish->contentType();

        $this->assertUnsupported($type === PublishPayload::TYPE_VIDEO ? 'videoPublishing' : 'imagePublishing');

        $body = array_filter([
            'board_id' => $this->boardIdFor($publish),
            'media_source' => $this->mediaSourceFor($publish, $type),
            'title' => $this->titleFor($publish),
            'description' => $publish->text !== '' ? $publish->text : null,
            'alt_text' => $this->altTextFor($publish),
            'board_section_id' => $this->sectionIdFor($publish),
            'link' => $publish->link,
            'parent_pin_id' => (string) ($publish->options['parent_pin_id'] ?? '') ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->api($account)->post(PinterestConfig::API_BASE.PinterestConfig::PINS, $body);

        $this->assertSuccessful($response, 'createPost');

        $data = (array) $response->json();
        $pinId = (string) ($data['id'] ?? '');

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: $pinId,
            permalink: $data['url'] ?? null,
            text: isset($data['description']) ? (string) $data['description'] : $publish->text,
            contentType: $type,
            attachedMediaIds: $publish->mediaIds,
            publishedAt: $this->readDate($data, 'created_at'),
            createdAt: $this->readDate($data, 'created_at'),
            raw: $data,
        );
    }

    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost
    {
        return $this->createPost($account, ['payload' => $this->payloadFromVariant($variant)]);
    }

    /**
     * Registers a video with `/media` and returns the media_id a Video Pin
     * references. An image needs no registration because it can be sent inline
     * as base64, so there is nothing to upload and no id to return.
     */
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string
    {
        $this->assertConfigured();

        $isVideo = str_starts_with((string) ($this->attribute($media, 'mime_type') ?? ''), 'video/');

        $this->assertUnsupported($isVideo ? 'videoPublishing' : 'imagePublishing');

        if (! $isVideo) {
            throw $this->unsupportedContentFormat(
                'imageUpload',
                'A Pinterest image Pin takes its image inline as base64 in media_source, so there is no separate image upload. Send the image on the variant instead.',
            );
        }

        $this->assertWithinByteLimit(
            (int) ($this->attribute($media, 'file_size') ?? 0),
            PinterestConfig::MAX_VIDEO_BYTES,
            'Upload a smaller video; Pinterest caps video Pins well below this size.',
        );

        $response = $this->api($account)->post(PinterestConfig::API_BASE.PinterestConfig::MEDIA, [
            'media_type' => 'video',
        ]);

        $this->assertSuccessful($response, 'uploadMedia');

        $data = (array) $response->json();
        $mediaId = (string) ($data['media_id'] ?? '');
        $uploadUrl = (string) ($data['upload_url'] ?? '');

        if ($mediaId === '' || $uploadUrl === '') {
            throw $this->buildApiException($response, UserFacingError::make(
                'media_id_missing',
                'Pinterest did not return a media registration for this video.',
                'The /media response carried no media_id or upload_url.',
                true,
                'Retry the upload; if it keeps failing, check that pins:write is granted for this app.',
            ));
        }

        $this->pushVideoBytes($account, $media, $uploadUrl, (array) ($data['upload_parameters'] ?? []));

        return $mediaId;
    }

    public function deletePost(SocialAccount $account, string $providerPostId): bool
    {
        $this->assertUnsupported('deletePost');
        $this->assertConfigured();

        $response = $this->api($account)->delete(
            PinterestConfig::API_BASE.PinterestConfig::PINS.'/'.$providerPostId,
        );

        $this->assertSuccessful($response, 'deletePost');

        return $response->successful();
    }

    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost
    {
        $this->assertConfigured();

        $response = $this->api($account)->get(PinterestConfig::API_BASE.PinterestConfig::PINS.'/'.$providerPostId);

        $this->assertSuccessful($response, 'getPost');

        $data = (array) $response->json();

        return new ProviderPost(
            provider: self::KEY,
            providerPostId: (string) ($data['id'] ?? $providerPostId),
            permalink: $data['url'] ?? null,
            text: isset($data['description']) ? (string) $data['description'] : null,
            contentType: $this->contentTypeFrom($data),
            publishedAt: $this->readDate($data, 'created_at'),
            createdAt: $this->readDate($data, 'created_at'),
            raw: $data,
        );
    }

    // ------------------------------------------------------------- Comments

    /**
     * Pinterest publishes no comment API, so this refuses. Returning an empty
     * list would be indistinguishable from "this Pin has no comments", which is
     * a different and false statement.
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
    }

    // ------------------------------------------------------------- Insights

    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch
    {
        $this->assertUnsupported('analytics');
        $this->assertConfigured();

        $postMetrics = [];

        foreach ($query->postIds as $postId) {
            $samples = $this->pinAnalytics($account, (string) $postId, $query);

            if ($samples !== []) {
                $postMetrics[(string) $postId] = $samples;
            }
        }

        return new AnalyticsBatch(
            provider: self::KEY,
            periodStart: $query->from,
            periodEnd: $query->to,
            accountMetrics: $this->accountAnalytics($account, $query),
            postMetrics: $postMetrics,
            granularity: $query->granularity,
        );
    }

    // ------------------------------------------------------------ Lifecycle

    public function disconnect(SocialAccount $account): void
    {
        $this->assertConfigured();

        // Pinterest revokes access from the app's own settings and exposes no
        // revoke endpoint, so there is no platform call to make here.
    }

    // ---------------------------------------------------------------- Errors

    /**
     * @return array<string, UserFacingError>
     */
    protected function errorMap(): array
    {
        return PinterestConfig::errorMap();
    }

    /**
     * Pinterest reports the condition in `message` and, on a 403, in `code`.
     */
    protected function extractErrorCode(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $code = $body['code'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    /**
     * @throws ProviderNotConfiguredException
     */
    protected function assertConfigured(): void
    {
        $credentials = (array) config('socialhub.providers.pinterest.credentials', []);

        $missing = [];

        foreach (['client_id' => 'PINTEREST_CLIENT_ID', 'client_secret' => 'PINTEREST_CLIENT_SECRET'] as $key => $envKey) {
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

    // ------------------------------------------------------------ Publishing

    /**
     * A Pin lives on a board, so the board is part of the identity rather than
     * an optional target. The connected account stores which board it posts to.
     */
    protected function boardIdFor(PublishPayload $publish): string
    {
        $boardId = (string) ($publish->options['board_id'] ?? '');

        if ($boardId !== '') {
            return $boardId;
        }

        throw $this->unsupportedContentFormat(
            'boardId',
            'Every Pinterest Pin must go on a board. Choose a destination board for this variant.',
        );
    }

    protected function sectionIdFor(PublishPayload $publish): ?string
    {
        $sectionId = (string) ($publish->options['board_section_id'] ?? '');

        return $sectionId !== '' ? $sectionId : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mediaSourceFor(PublishPayload $publish, string $type): array
    {
        if ($type === PublishPayload::TYPE_VIDEO) {
            $mediaId = (string) ($publish->mediaIds[0] ?? '');

            if ($mediaId === '') {
                throw $this->unsupportedContentFormat(
                    'videoPublishing',
                    'A Pinterest video Pin needs a media_id. Register the video through the media upload first, then attach the returned id.',
                );
            }

            return [
                'source_type' => 'video_id',
                'media_id' => $mediaId,
                'cover_image_url' => $this->mediaUrlOf($publish->options) ?? '',
            ];
        }

        return $this->imageMediaSource($publish);
    }

    /**
     * @return array<string, mixed>
     */
    protected function imageMediaSource(PublishPayload $publish): array
    {
        $inline = (string) ($publish->options['image_base64'] ?? '');

        if ($inline !== '') {
            return [
                'source_type' => 'image_base64',
                'content_type' => (string) ($publish->options['content_type'] ?? 'image/jpeg'),
                'data' => $inline,
            ];
        }

        $url = $this->mediaUrlOf($publish->options)
            ?? (isset($publish->mediaIds[0]) && str_starts_with((string) $publish->mediaIds[0], 'https://') ? (string) $publish->mediaIds[0] : null);

        if ($url !== null) {
            return ['source_type' => 'image_url', 'url' => $url];
        }

        $path = (string) ($publish->options['storage_path'] ?? '');

        if ($path !== '') {
            return $this->mediaSourceFromFile($publish, $path);
        }

        throw $this->unsupportedContentFormat(
            'imageMedia',
            'A Pinterest Pin needs an image. Provide a public image URL, a base64 image, or the stored file for this variant.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function mediaSourceFromFile(PublishPayload $publish, string $path): array
    {
        $disk = (string) (($publish->options['storage_disk'] ?? '') ?: config('socialhub.media.disk', 'local'));
        $contents = Storage::disk($disk)->get($path);

        if ($contents === '') {
            throw new ProviderApiException(self::KEY, 0, null, UserFacingError::make(
                'media_empty',
                'This image could not be published because the stored file is empty.',
                sprintf('The stored media file "%s" on disk "%s" is empty.', $path, $disk),
                false,
                'Re-upload the image, then republish the post.',
            ));
        }

        $this->assertWithinByteLimit(
            strlen($contents),
            PinterestConfig::MAX_IMAGE_BYTES,
            'Use a smaller image; Pinterest accepts up to 20 MB for an inline base64 Pin image.',
        );

        return [
            'source_type' => 'image_base64',
            'content_type' => (string) ($publish->options['content_type'] ?? $this->mimeFromPath($path)),
            'data' => base64_encode($contents),
        ];
    }

    protected function titleFor(PublishPayload $publish): string
    {
        $title = trim($publish->title ?? '');

        if ($title !== '') {
            return mb_substr($title, 0, PinterestConfig::MAX_TITLE_LENGTH);
        }

        $firstLine = strtok(trim($publish->text), "\n");
        $derived = trim($firstLine === false ? '' : $firstLine);

        return mb_substr($derived !== '' ? $derived : 'Untitled Pin', 0, PinterestConfig::MAX_TITLE_LENGTH);
    }

    protected function altTextFor(PublishPayload $publish): ?string
    {
        $alt = (string) ($publish->options['alt_text'] ?? '');

        return $alt !== '' ? mb_substr($alt, 0, PinterestConfig::MAX_ALT_TEXT_LENGTH) : null;
    }

    protected function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    protected function pushVideoBytes(SocialAccount $account, MediaAsset $media, string $uploadUrl, array $parameters): void
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

        $response = \Illuminate\Support\Facades\Http::withToken($this->tokens->resolve($account, self::KEY)->accessToken)
            ->timeout((int) config('socialhub.publishing.timeout', 30))
            ->withBody($contents, 'application/octet-stream')
            ->post($uploadUrl, $parameters);

        if (! $response->successful()) {
            $this->logProviderFailure('uploadMedia.push', $response);

            throw $this->buildApiException($response);
        }
    }

    // ------------------------------------------------------------- Insights

    /**
     * @return list<MetricSample>
     */
    protected function pinAnalytics(SocialAccount $account, string $pinId, AnalyticsQuery $query): array
    {
        $metrics = $this->requestedMetricTypes($query->metrics);

        if ($metrics === []) {
            return [];
        }

        $response = $this->api($account)->get(
            PinterestConfig::API_BASE.PinterestConfig::PINS.'/'.$pinId.'/analytics',
            [
                'start_date' => $query->from->format('Y-m-d'),
                'end_date' => $query->to->format('Y-m-d'),
                'metric_types' => implode(',', $metrics),
                'app_types' => 'ALL',
            ],
        );

        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        return $this->samplesFrom((array) $response->json());
    }

    /**
     * @return list<MetricSample>
     */
    protected function accountAnalytics(SocialAccount $account, AnalyticsQuery $query): array
    {
        $metrics = $this->requestedMetricTypes($query->metrics);

        if ($metrics === []) {
            return [];
        }

        $response = $this->api($account)->get(
            PinterestConfig::API_BASE.PinterestConfig::USER_ACCOUNT_ANALYTICS,
            [
                'start_date' => $query->from->format('Y-m-d'),
                'end_date' => $query->to->format('Y-m-d'),
                'metric_types' => implode(',', $metrics),
                'app_types' => 'ALL',
            ],
        );

        // user_account:read is a separate grant; without it this is genuinely
        // unavailable rather than empty.
        if (! $response->successful()) {
            $this->assertNotRateLimited($response);

            return [];
        }

        return $this->samplesFrom((array) $response->json());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<MetricSample>
     */
    protected function samplesFrom(array $body): array
    {
        $samples = [];

        foreach ((array) ($body['all'] ?? []) as $bucket) {
            foreach ((array) $bucket as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $date = (string) ($entry['date'] ?? '');

                foreach ((array) ($entry['metrics'] ?? []) as $type => $value) {
                    $normalised = PinterestConfig::ANALYTICS_METRICS[(string) $type] ?? null;

                    if ($normalised === null) {
                        continue;
                    }

                    $samples[] = new MetricSample(
                        metric: $normalised,
                        value: (float) $value,
                        periodStart: $date !== '' ? $date : null,
                        periodEnd: $date !== '' ? $date : null,
                        granularity: 'day',
                    );
                }
            }
        }

        return $samples;
    }

    /**
     * @param  list<string>  $only
     * @return list<string>
     */
    protected function requestedMetricTypes(array $only): array
    {
        $wanted = $only !== [] ? $only : array_values(PinterestConfig::ANALYTICS_METRICS);

        $types = [];

        foreach (PinterestConfig::ANALYTICS_METRICS as $type => $normalised) {
            if (in_array($normalised, $wanted, true)) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    // -------------------------------------------------------------- Helpers

    /**
     * @param  array<string, mixed>  $body
     */
    protected function pinterestBookmarkFrom(array $body): ?string
    {
        $bookmark = $body['bookmark'] ?? null;

        return is_string($bookmark) && $bookmark !== '' ? $bookmark : null;
    }

    /**
     * @param  array<string, mixed>  $pin
     */
    protected function contentTypeFrom(array $pin): string
    {
        $media = (array) ($pin['media'] ?? []);
        $mediaType = (string) ($media['media_type'] ?? 'image');

        return $mediaType === 'video' ? PublishPayload::TYPE_VIDEO : PublishPayload::TYPE_IMAGE;
    }
}
