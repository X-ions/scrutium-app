# SocialHub CMS — Social Provider Architecture

## Core Rule

**No core code may branch on a platform name.** All platform behaviour lives behind
`SocialProviderInterface`, resolved through `SocialProviderRegistry`. The publishing engine,
composer, analytics engine, and database layer know only the interface and
`ProviderCapabilities`.

## Contracts

```php
namespace App\Services\Social\Contracts;

interface SocialProviderInterface
{
    public function authenticate(AuthRequest $request): AuthSession;
    public function refreshToken(SocialAccount $account): TokenSet;
    public function getAccount(SocialAccount $account): AccountProfile;
    public function getPages(SocialAccount $account): array;      // pages/channels/profiles
    public function createPost(SocialAccount $account, array $payload): ProviderPost;
    public function publishPost(SocialAccount $account, PostVariant $variant): ProviderPost;
    public function uploadMedia(SocialAccount $account, MediaAsset $media): string;
    public function deletePost(SocialAccount $account, string $providerPostId): bool;
    public function getPost(SocialAccount $account, string $providerPostId): ProviderPost;
    public function getComments(SocialAccount $account, ?string $providerPostId = null): array;
    public function replyToComment(SocialAccount $account, ProviderComment $comment, string $body): void;
    public function getAnalytics(SocialAccount $account, AnalyticsQuery $query): AnalyticsBatch;
    public function getFollowers(SocialAccount $account): FollowerStats;
    public function getSupportedFeatures(): ProviderCapabilities;
    public function disconnect(SocialAccount $account): void;
    public function verifyWebhook(VerifiedWebhookRequest $request): bool;
    public function normalizeWebhook(array $payload): array;
}
```

### ProviderCapabilities

An immutable value object (not a bag of `if`s). Providers return a fully-populated
instance; the registry merges account-level narrowing.

```php
final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $publishing = false,
        public bool $imagePublishing = false,
        public bool $videoPublishing = false,
        public bool $carouselPublishing = false,
        public bool $textPublishing = false,
        public bool $stories = false,
        public bool $reels = false,
        public bool $shorts = false,
        public bool $scheduling = false,          // platform-side scheduled publish
        public bool $comments = false,
        public bool $commentReplies = false,
        public bool $likes = false,
        public bool $shares = false,
        public bool $views = false,
        public bool $reach = false,
        public bool $impressions = false,
        public bool $saves = false,
        public bool $followers = false,
        public bool $analytics = false,
        public bool $webhooks = false,
        public bool $linkPosts = false,
        public bool $firstComment = false,
        public bool $deletePost = false,
        /** @var list<string> */
        public array $metrics = [],
        /** @var list<string> */
        public array $constraints = [],
        /** @var list<string> */
        public array $requiredScopes = [],
    ) {}

    public function supports(string $capability): bool;
    public function assertSupports(string $capability): void;  // throws UnsupportedCapability
    public function toArray(): array;
}
```

`assertSupports()` throws `App\Exceptions\Social\UnsupportedCapabilityException`, which the
publishing engine converts into a user-facing message on **that variant only**.

## Real Platform Capabilities (verified against official docs)

`verified_on: 2026-09-29`. Any capability not listed as available is **not** implemented.

| Capability | Facebook | Instagram | YouTube | TikTok | X | LinkedIn | Pinterest |
|---|---|---|---|---|---|---|---|
| Text publish | ✅¹ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ |
| Image publish | ✅ | ✅ | ✅ thumbnail | ✅ | ✅ | ✅ | ✅ |
| Video publish | ✅ | ✅ Reels | ✅ upload | ✅ | ❌ | ✅ native | ✅ |
| Carousel | ✅ | ✅ | ❌ | ✅ photo post | ❌ | ✅ document | ❌ |
| Link posts | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Stories | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Scheduling | ❌ | ❌ | ✅ `status.publishAt` | ✅ | ❌ | ✅ | ✅ |
| Comments read | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Comment replies | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Views metric | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ |
| Reach / impressions | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ✅ |
| Followers | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Webhooks | ✅ Page | ✅ limited | ✅ PubSub | ✅ | ❌ (Activity) | ❌ | ❌ |
| Delete post | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ |

¹ Facebook text-only posts are deprecated for most page types; provider returns a clear
explanatory error rather than silently failing.

### Approval-gated (documented, not bypassed)

| Platform | Gate |
|---|---|
| Instagram / Facebook | App Review + `instagram_basic`, `instagram_content_publish`, `pages_manage_posts`; Business/Creator account linked to a Facebook Page **required** |
| TikTok | App Audit for non-approved clients; `video.publish` + `video.upload` scopes |
| YouTube | OAuth verification for write scope; unverified apps are **test users only** (100 users) |
| LinkedIn | Organization Page posting needs `w_organization_manage`; member posting `w_member_social` |
| Pinterest | `pins:read`, `pins:write`, `boards:read` |
| X | Elevated (paid) access tier required for write endpoints on v2 |

Providers read these from `config/socialhub.php`. When credentials are missing, the provider
reports `status = 'not_configured'` and the UI hides publish affordances rather than faking them.

## Registry

```php
final class SocialProviderRegistry
{
    /** @var array<string, class-string<SocialProviderInterface>> */
    private array $map;

    public function register(string $key, string $class): void;   // from config
    public function get(string $provider): SocialProviderInterface;
    public function has(string $provider): bool;
    /** @return list<string> */
    public function all(): array;
    /** @return list<ProviderDescriptor> */
    public function describe(): array;  // name, badge, capabilities, configured, reason
}
```

Bootstrapped in `SocialHubServiceProvider` from `config/socialhub.php`; adding a provider is a
config entry plus one class — **no other file changes**.

## Provider internal structure

```
app/Services/Social/Providers/
├── FacebookProvider.php
├── InstagramProvider.php
├── YouTubeProvider.php
├── TikTokProvider.php
├── XProvider.php
├── LinkedInProvider.php
├── PinterestProvider.php
└── Concerns/
    ├── UsesOAuth2AuthorizationCode.php   // state + PKCE flow, shared
    ├── InteractsWithGraphApi.php         // Meta graph pagination + retry
    ├── MapsProviderErrors.php            // error code → UserFacingError
    └── RespectsRateLimitHeaders.php      // 429 / Retry-After / X-RateLimit-*
```

Common concerns keep each provider small; a provider file should stay under ~400 lines.

## Error model

`UserFacingError { code, userMessage, technicalMessage, retryable, remediation }`
rendered by the variant status card:

> Instagram couldn't publish this post because the connected account doesn't have
> permission to publish. Reconnect the account or change the content format.

## Rate limiting

Per-provider limits in `config/socialhub.php` (`rate_limits`), enforced by
`ProviderRateLimiter` using Redis token buckets keyed `ratelimit:{provider}:{tenant}`.
`429` → read `Retry-After` / `X-RateLimit-Reset` → `RateLimitException(retryAfter)` → job
released with delay. Never retries synchronously in a request.

## Metrics vocabulary

Normalized `MetricType` enum (`views`, `likes`, `comments`, `shares`, `saves`,
`reactions`, `clicks`, `reach`, `impressions`, `engagements`, `followers`, `watch_time_minutes`,
`avg_view_duration_seconds`, `completion_rate`). Providers map to it; a metric a platform
does not report is simply **absent**, never zero-filled. The dashboard labels cross-platform
totals as "reported by platform" and shows per-platform availability.
