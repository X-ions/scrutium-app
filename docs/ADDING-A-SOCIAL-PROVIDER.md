# Adding a social provider

A provider is one class plus one config entry. You should not need to touch the
publishing engine, the analytics engine, the composer, the database, or any
controller.

---

## 1. Scaffold it

```bash
php artisan socialhub:make-provider bluesky
```

That writes `app/Services/Social/Providers/BlueskyProvider.php` and adds the
entry to `config/socialhub.php`. Open both and replace the stubs.

## 2. Implement the interface

`app/Services/Social/Contracts/SocialProviderInterface` has 15 methods plus
`verifyWebhook()` and `normalizeWebhook()`. You must implement all of them, but
you do **not** have to support all of them. For anything the platform does not
offer:

```php
public function getComments(SocialAccount $account, ?string $providerPostId = null): array
{
    $this->assertUnsupported('comments');
}
```

`assertUnsupported()` throws `UnsupportedCapabilityException` carrying a
`UserFacingError` with a message the user can act on. The composer hides the
control, and a job that hits it anyway fails that variant with a clear reason
instead of a 400 from the platform.

Never fake a response. A provider that cannot do something throws.

## 3. Declare your capabilities, and mean them

```php
public function getSupportedFeatures(): ProviderCapabilities
{
    return PlatformCapabilities::bluesky();
}
```

Add the method to `app/Services/Social/Capabilities/PlatformCapabilities.php`
and register the key in `platforms()` and `for()`. This is the single source of
truth: the composer reads it to decide which fields to show, the publishing
engine reads it to reject unsupported content before dispatching, and the
capabilities page renders straight from it.

Set `VERIFIED_ON` to the date you last checked the platform's official docs.
`php artisan socialhub:doctor` prints it.

## 4. Fill in the OAuth handshake

Most providers are OAuth 2. Reuse the trait:

```php
use App\Services\Social\OAuth\UsesOAuth2AuthorizationCode;

final class BlueskyProvider implements SocialProviderInterface
{
    use UsesOAuth2AuthorizationCode;

    public function authenticate(AuthRequest $request): AuthSession
    {
        // The trait handles state validation, PKCE and the token exchange.
    }
}
```

Rules, in order of how often they are broken:

- The client secret is used **only** in the server-side token exchange. It is
  read from `config()`, never from a request or a view.
- Declare `requiredScopes` in your capabilities. The composer refuses content
  the granted scopes cannot deliver.
- `refreshToken()` must return the provider's real rotation semantics. If the
  platform does not rotate, return the previous refresh token.
- Throw `TokenRevokedException` on `invalid_grant` and `TokenExpiredException` on
  a genuinely expired token. They are different conditions and the token
  lifecycle treats them differently.

## 5. Add credentials to `.env.example`

```dotenv
BLUESKY_CLIENT_ID=
BLUESKY_CLIENT_SECRET=
BLUESKY_REDIRECT_URI="${APP_URL}/socialhub/accounts/bluesky/callback"
BLUESKY_WEBHOOK_SECRET=
```

Placeholders only. Add the same keys to your deployment's secret store.

## 6. Map errors to user-facing messages

Use the shared concern:

```php
use App\Services\Social\Providers\Concerns\MapsProviderErrors;

protected function userErrorFor(int $status, array $body): UserFacingError
```

The message is what the user reads in the variant status card. The technical
message goes to the log. Never put a token in either.

## 7. Rate limits

Add a `rate_limits` entry:

```php
'bluesky' => [
    'capacity' => 300,
    'refill_per_second' => 1.0,
    'bucket_ttl_seconds' => 600,
],
```

`ProviderRateLimiter` applies it before every request. On a `429` read
`Retry-After` / `X-RateLimit-Reset` via `RespectsRateLimitHeaders` and throw
`RateLimitException($retryAfter)` — the job is released with that delay rather
than consuming a retry attempt.

## 8. Write the tests

`tests/Feature/Social/BlueskyProviderTest.php`, using `Http::fake()`. Cover:

- the correct real endpoints are called
- an unsupported operation throws `UnsupportedCapabilityException` with a
  friendly message
- `429` becomes `RateLimitException` with the right `retryAfter`
- `invalid_grant` becomes `TokenRevokedException`
- a bad webhook signature becomes `WebhookSignatureException`
- provider error codes map to the right `UserFacingError`

Never hit a real network in a test.

## 9. Check it end to end

```bash
php artisan socialhub:doctor          # capabilities, credentials, health
php artisan test --filter=Bluesky     # your provider tests
php artisan test                      # nothing else may regress
```

---

## What you must not do

| Don't | Why |
|---|---|
| Branch on the platform name in core code | That is the thing the registry exists to prevent |
| Return synthetic data for an unsupported call | The UI will show a lie |
| Scrape a platform that has an official API | Breaks on their terms, and their terms govern your app |
| Put a client secret or access token in a log, view, or notification | `NotificationService` redacts, but do not rely on that as the only guard |
| Mark a capability `true` because it "should work" | The capability matrix is what the UI trusts |

## Where things live

| Concern | File |
|---|---|
| Interface | `app/Services/Social/Contracts/SocialProviderInterface.php` |
| Capability flags | `app/Services/Social/Contracts/ProviderCapabilities.php` |
| Per-platform matrix | `app/Services/Social/Capabilities/PlatformCapabilities.php` |
| Registry | `app/Services/Social/SocialProviderRegistry.php` |
| HTTP + rate limiting | `app/Services/Social/ProviderHttpClient.php`, `ProviderRateLimiter.php` |
| OAuth helpers | `app/Services/Social/OAuth/` |
| Error mapping | `app/Services/Social/Providers/Concerns/MapsProviderErrors.php` |
| Registration | `config/socialhub.php` |

## How approval-gated platforms work

If a platform requires App Review or a verification before your app can publish,
record it rather than working around it:

```php
'requires_app_review' => true,
```

The UI shows the gate on the accounts and capabilities pages, and
`socialhub:doctor` warns. A user can still connect the account and see their
existing analytics; the write operations are refused by the platform, and the
error message says why.
