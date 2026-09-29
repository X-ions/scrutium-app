# SocialHub CMS — Security Design

## Threat model summary

| Threat | Control |
|---|---|
| OAuth state/PKCE forgery | `oauth_states` table, single-use, 10-min TTL, constant-time compare |
| Token theft via XSS/logs | `encrypted` cast (AES-256-GCM), redaction in log channel, `$hidden`, never serialized to API |
| Token theft at rest / backup leak | Laravel `encrypted` cast + optional `SOCIALHUB_TOKEN_KEY` separate key |
| CSRF on state-changing routes | `web` middleware group + CSRF token; webhook routes excluded by signature |
| IDOR across tenants | `BelongsToTenant` scope on every model + policies + `authorize()` in every controller |
| Webhook spoofing | HMAC-SHA256 signature verify (per-provider), timestamp tolerance, replay window |
| SSRF via media URLs | No arbitrary outbound fetch; media upload only via multipart to provider, allow-listed hosts |
| Malicious uploads | MIME sniffed server-side, extension allow-list, size caps, `finfo` not client MIME, stored outside webroot on `public` disk |
| API abuse | per-tenant + per-user throttle, per-provider token bucket, global Laravel rate limiter |
| Privilege escalation | Roles resolved from `organization_members`, never from request body |
| Secret exposure in git | `.env.example` placeholders only; CI secret scan; `config()` reads only |

## OAuth

1. `GET /social/{provider}/connect` → generate `state` (32 bytes, `random_bytes`), store row
   with `expires_at = now()+10min`. PKCE: store `code_verifier` when provider declares support.
2. Redirect to provider authorize URL. `client_secret` only ever used in the server-side
   token exchange POST.
3. `GET /social/{provider}/callback` → look up `state`, reject if missing/expired/already used
   (delete on use), then exchange code. Verify `state` with `hash_equals`.
4. Tokens stored in `social_account_tokens` with `encrypted` cast. `access_token`,
   `refresh_token`, `id_token` are all encrypted; `expires_at` tracked for the status UI.
5. Refresh: `RefreshTokenJob` runs on schedule for accounts expiring <24h. On `invalid_grant`
   → account `status = revoked`, notification raised, no auto-retry loop.
6. Disconnect: provider `disconnect()` best-effort, then hard-delete tokens, mark account
   `disconnected`.

`config/socialhub.php` reads credentials from env; `php artisan socialhub:doctor` reports which
providers are configured **without printing any secret**.

## Tenant isolation

- `App\Models\Concerns\BelongsToTenant` applies a global scope resolving the active tenant.
- Every controller method resolves the record through a tenant-scoped query, then calls
  `$this->authorize()`. Route-model binding on non-scoped models is forbidden by a test.
- `tests/Feature/TenantIsolationTest.php` iterates every resource and asserts a user from
  tenant B receives 403/404 for tenant A's ids.

## Roles

Reuse `App\Enums\UserRole` (Owner, Admin, Manager, Analyst, Viewer) with explicit abilities:

| Ability | Owner | Admin | Manager | Analyst | Viewer |
|---|---|---|---|---|---|
| view dashboard/analytics | ✓ | ✓ | ✓ | ✓ | ✓ |
| create/edit drafts | ✓ | ✓ | ✓ | — | — |
| publish/schedule | ✓ | ✓ | ✓ | — | — |
| manage media | ✓ | ✓ | ✓ | ✓ | — |
| connect/disconnect social accounts | ✓ | ✓ | — | — | — |
| reply to comments | ✓ | ✓ | ✓ | ✓ | — |
| manage team & settings | ✓ | ✓ | — | — | — |
| billing | ✓ | — | — | — | — |

## Webhooks

```
POST /webhooks/{provider}
```
- Raw body read **before** any JSON parsing.
- Signature verified per provider (Meta `X-Hub-Signature-256`, TikTok HMAC, YouTube Pub/Sub
  OIDC token, generic `X-Signature: sha256=<hmac>`) with `hash_equals`.
- Reject if timestamp skew > 5 min.
- Insert into `webhook_events` on `(provider, event_id)` unique index — duplicate insert is
  swallowed and returns 200 immediately.
- Actual handling is queued (`ProcessWebhookJob`).

## Secrets & logging

- `log` channel for jobs adds a `RedactsCredentials` processor scrubbing
  `access_token|refresh_token|client_secret|authorization|code_verifier|password` keys.
- `SocialAccount` model has `#[hidden]` token relations; API resources never include them.
- `php artisan socialhub:doctor` and health endpoints report `configured: bool` only.

## HTTP security headers

Middleware `SecurityHeaders` sets `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` restricting camera/
microphone/geolocation, HSTS in production, and a CSP that is nonce-based for scripts.

## Rate limiting

| Scope | Limit |
|---|---|
| `api` | 120/min per user |
| `oauth` | 10/min per user |
| `publish` | 30/hour per tenant |
| `media-upload` | 60/hour per user |
| `webhook` | 600/min per provider+IP |
| per provider API | token bucket from `config/socialhub.php` |

## Definition of security done

`tests/Feature/Security/*` covers: tenant IDOR, token non-exposure in API responses, CSRF,
webhook signature rejection, duplicate webhook idempotency, upload MIME spoofing, privilege
escalation attempts, and log redaction.
