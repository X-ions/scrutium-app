# SocialHub CMS

A multi-tenant social media management system. Connect social accounts, write
content once, customise it per network, publish or schedule it through
background workers, and track the results in one dashboard.

It lives inside the existing Scrutium Laravel 12 application and reuses that
app's tenant model, user roles, design system and queue infrastructure.

---

## The workflow

```
Connect accounts → compose → pick networks → customise each → preview
                  → publish now / schedule → workers publish → analytics
```

**One network failing never blocks the others.** A post targeting six networks
produces six independently-tracked variants, and the UI shows each one's status
separately. That is the central design decision: a TikTok permission error must
not stop the Facebook post from going out.

## Stack

Laravel 12 · PHP 8.3 · Blade + Alpine.js + Tailwind CSS v4 · Vite 7 ·
PostgreSQL (SQLite for local) · Redis queue and cache · S3 or Cloudflare R2 ·
Pest for tests · Docker for deployment.

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# SQLite for a quick start
touch database/database.sqlite
php artisan migrate

npm run build          # or: npm run dev
php artisan serve
```

### With Docker

Brings up nginx, php-fpm, a worker, the scheduler, PostgreSQL, Redis and MinIO.

```bash
cp .env.example .env && php -r "echo 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
docker compose --profile tools run --rm minio-init
docker compose --profile tools run --rm migrate
docker compose up -d
```

The app is then on <http://localhost:8080>. MinIO's console is on
<http://localhost:9001> (`minioadmin` / `minioadmin`).

### Without Docker

You need a queue worker and a scheduler, or nothing will publish:

```bash
php artisan queue:work --queue=webhooks,publishing,default
php artisan schedule:work
```

## Environment

Full list in `.env.example` (placeholders only — no real secrets are committed).
The essentials:

| Variable | Purpose |
|---|---|
| `DB_*` | PostgreSQL connection |
| `QUEUE_CONNECTION` | `redis` in production. `database` works for a single-box install |
| `REDIS_*` | Queue, cache and per-provider rate-limit buckets |
| `SOCIALHUB_MEDIA_DISK` | `s3` or `r2` in production. **Never `public`** — the app refuses it |
| `AWS_*` | Bucket credentials, and `AWS_ENDPOINT` for R2 |
| `TRUSTED_PROXIES` | Which proxies may set `X-Forwarded-*`. See below |
| `LOG_CHANNEL` | `structured` in production; it emits JSON for a log shipper |
| `META_*`, `TIKTOK_*`, `YOUTUBE_*`, `X_*`, `LINKEDIN_*`, `PINTEREST_*` | Per-provider OAuth credentials |

`TRUSTED_PROXIES` defaults to trusting nothing. Behind a load balancer or the
Vercel edge, set it to the balancer's address — or `*` only when nothing but
that edge can reach the app. Trusting every hop lets a client forge
`X-Forwarded-For` and walk straight past rate limiting.

## Connecting a social account

1. Create an app in the platform's developer console.
2. Add the redirect URI shown by `php artisan socialhub:doctor`:
   `https://your-domain/socialhub/accounts/{provider}/callback`
3. Put the client id and secret in `.env`.
4. Restart, then run `php artisan socialhub:doctor` — it reports `Ready` per
   provider and never prints a value.
5. Connect from **Social Accounts** in the UI.

Tokens are encrypted at rest with the application key, are never sent to the
browser, and are redacted from every log line. Disconnecting deletes them.

**Some platforms need approval before publishing works at all.** Instagram and
TikTok require App Review; YouTube requires OAuth verification; X requires an
elevated access tier. The accounts page shows the gate instead of letting you
discover it by failing a publish. Details are in `SOCIAL-PROVIDERS.md`.

## How publishing works

Publishing never happens inside an HTTP request.

```
Schedule a post
      ↓
One ScheduledPost + one job per network variant
      ↓
Worker claims the job (WithoutOverlapping on the variant id)
      ↓
Provider.publishPost()
      ↓
Published · retried with backoff · released on 429 · failed after N tries
```

Properties this buys, each covered by a test:

- **Independent** — one variant's failure never rolls back another's success.
- **Idempotent** — a job that runs twice does not double-post. A worker can die
  mid-publish and the retry completes it.
- **Rate-limit aware** — a `429` releases the job with the provider's
  `Retry-After` and does *not* consume a retry attempt, so an hour of throttling
  is not recorded as a failure.
- **Crash recoverable** — a variant stuck in `publishing` is re-queued by the
  scheduler after ten minutes.
- **Bounded** — attempts are capped and the outcome is recorded on the variant
  with a message the user can act on.

## Analytics

Metrics are normalised into one vocabulary (`views`, `likes`, `reach`,
`impressions`, `watch_time_minutes`, …) but a metric a platform does not report
is **absent, never zero**. The dashboard states which platforms report which
metrics rather than implying that a missing number means zero.

Cross-platform totals carry a comparability structure, because a YouTube view
and an Instagram view are not the same thing. The UI labels the difference
instead of quietly summing unlike units.

## Comments

One inbox across every network that offers a comments API. Where a platform
cannot reply, the reply box is absent and the reason is stated — Pinterest pins
have no public comment API, for instance, so replying happens on the original
post.

Replies are queued, never sent inside the request. A reply already recorded as
sent or failed is not retried, because a public reply cannot be taken back.

## Security

See `SECURITY.md` for the full threat model. The controls that matter most:

- OAuth state is single-use with a 10-minute TTL; PKCE where supported.
- Tokens are encrypted at rest and redacted from logs, notifications and every
  API response.
- Uploads are typed from their own bytes, not the client's `Content-Type` or
  filename, and EXIF GPS data is stripped.
- Webhooks are signature-verified over the raw body, with a replay window, and
  deduplicated by event id.
- Every read is tenant-scoped by a global scope; a cross-tenant id returns 404,
  not 403, so it cannot confirm a record exists.
- Structured security headers with a nonce-based CSP.

`tests/Feature/Security/SecurityControlsTest.php` asserts each of these.

## Testing

```bash
composer test                              # or: php artisan test
php artisan test --filter=SocialHub
php artisan test --filter=Publishing
php artisan test --filter=Media
php artisan test --filter=Security
php vendor/bin/pint --test                 # style
npm run lint                               # JS
```

The suite covers the failure paths that matter, not just the happy ones:
expired and revoked tokens, rate limiting, provider outages, duplicate webhook
delivery, duplicate publish requests, partial multi-network failure, worker
restart, MIME spoofing, and tenant isolation.

Two guards are worth knowing about because they catch whole classes of defect:

- `SocialHubRouteBindingTest` fails if a route's `{placeholder}` does not match
  the controller's argument name. A mismatch makes Laravel hand the controller
  an empty model rather than 404 — silent, and capable of becoming an
  authorization hole.
- `SecurityControlsTest` fails if a log channel loses its redaction processor.

## Adding a platform

```bash
php artisan socialhub:make-provider bluesky
```

One class plus one config entry. See
[`ADDING-A-SOCIAL-PROVIDER.md`](ADDING-A-SOCIAL-PROVIDER.md).

## Commands

| Command | Purpose |
|---|---|
| `socialhub:doctor` | What is configured, what each network supports, component health. Never prints a secret |
| `socialhub:make-provider {key}` | Scaffold a provider |
| `socialhub:comments:sync` | Queue a comment sync (`--sync` runs inline) |
| `socialhub:tokens:refresh` | Refresh expiring authorizations (`--warn-only` just warns) |
| `socialhub:webhooks:prune` | Delete old webhook events (`--dry-run` to preview) |

Scheduled: `publish:due` every minute, tokens hourly, comments every fifteen
minutes, analytics hourly, aggregation and partition maintenance daily. **Run
exactly one scheduler.**

## Further reading

| Document | Contents |
|---|---|
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Docker, workers, backups, monitoring, rollback, troubleshooting |
| [`ADDING-A-SOCIAL-PROVIDER.md`](ADDING-A-SOCIAL-PROVIDER.md) | The 5-minute path to a new platform |
| [`ARCHITECTURE.md`](../ARCHITECTURE.md) | System design |
| [`DATABASE.md`](../DATABASE.md) | Schema and retention |
| [`SOCIAL-PROVIDERS.md`](../SOCIAL-PROVIDERS.md) | Interface and verified capability matrix |
| [`SECURITY.md`](../SECURITY.md) | Threat model |
