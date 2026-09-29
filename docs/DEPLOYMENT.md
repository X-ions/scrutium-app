# Deployment

## What has to be running

| Component | Why it cannot be optional |
|---|---|
| Web (php-fpm behind nginx, or the FPM pool directly) | Serves the UI and the read-only JSON |
| Queue worker | **Publishing never happens in a request.** Without a worker, "Publish now" queues a job that nothing executes |
| Scheduler | Dispatches due posts, refreshes tokens, syncs analytics and comments, prunes webhooks |
| PostgreSQL 16+ | Primary store. `analytics_metrics` is the largest table |
| Redis | Queue, cache and per-provider rate-limit buckets. Redis runs with `noeviction` on purpose |
| S3 or R2 | Media. **Not** the `public` disk — the app refuses to write uploads there in production |

Run exactly **one** scheduler. A second dispatches every scheduled task twice and
publishes duplicates. Workers scale horizontally; the scheduler does not.

## Environment

Copy `.env.example` and fill it in. Nothing here is optional except where marked.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://social.example.com
APP_KEY=            # php artisan key:generate --show

DB_CONNECTION=pgsql
DB_HOST=…
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=…
REDIS_PASSWORD=…

# Proxy in front of the app. Set to the balancer's address, or * only when
# nothing but that edge can reach the app. Trusting every hop lets a client
# forge X-Forwarded-For and walk past rate limiting.
TRUSTED_PROXIES=*

# Media
SOCIALHUB_MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=…
AWS_BUCKET=…
# Cloudflare R2 only:
AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com
AWS_URL=https://<account>.r2.cloudflarestorage.com

LOG_CHANNEL=structured
LOG_LEVEL=info
```

Then the per-provider credentials. `php artisan socialhub:doctor` tells you
exactly which keys are missing, and never prints a value.

## First deploy

```bash
# 1. Build
docker compose build

# 2. Create the media bucket
docker compose --profile tools run --rm minio-init      # or create it by hand on S3/R2

# 3. Apply migrations as a single step, not on every container boot
docker compose --profile tools run --rm migrate

# 4. Warm the caches
docker compose run --rm app php artisan config:cache
docker compose run --rm app php artisan route:cache
docker compose run --rm app php artisan view:cache

# 5. Start
docker compose up -d
```

Only the `migrate` role runs migrations. If every replica ran them on boot, a
rolling deploy would have N processes altering the same tables at once.

## Zero-downtime releases

```bash
docker compose build
docker compose --profile tools run --rm migrate      # expand before you switch traffic
docker compose up -d --no-deps app
```

The old and new app versions must be able to run against the same schema. That
is why migrations here are additive: add a nullable column, backfill, then start
reading it. Never drop a column in the same release that stops writing it.

To roll back, redeploy the previous image tag. **Roll the code back, not the
schema** — a rolled-back schema cannot recover rows written by the new code.

## Queue

Three named queues, in priority order:

| Queue | Carries |
|---|---|
| `webhooks` | Inbound provider events. Small and quick; they should never wait behind a video upload |
| `publishing` | Upload and publish. Slower — providers are called, sometimes twice |
| `default` | Analytics sync, comment sync, token refresh |

```bash
php artisan queue:work --queue=webhooks,publishing,default --tries=3 \
  --max-time=3600 --max-jobs=1000
```

`--max-time` and `--max-jobs` recycle workers, which bounds the effect of any
memory leak in a provider's JSON handling.

**If publishing is backed up, check in this order:** is the worker running, is
the queue the same name the app dispatches to, is Redis reachable, then
`select count(*) from failed_jobs`.

`--tries=3` with exponential backoff is a floor, not a ceiling — a rate-limited
job is *released* with the provider's `Retry-After` and does not consume an
attempt, so a platform throttling for an hour is not treated as a failure.

## Scheduler

```bash
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

or run `php artisan schedule:work` as a service. What it drives:

| Task | Cadence | Effect if stopped |
|---|---|---|
| `publish:due` | every minute | **Scheduled posts never go out.** This is the important one |
| `publishing:prune-attempts` | daily | Publishing attempt history grows unbounded |
| `socialhub:tokens:refresh` | hourly | Authorizations lapse; accounts need reconnecting |
| `socialhub:analytics:sync` | hourly | Dashboards go stale |
| `socialhub:analytics:aggregate` | daily at 03:15 | Roll-ups are not refreshed |
| `socialhub:analytics:partitions` | monthly | Old analytics partitions are not maintained (MySQL only) |
| `socialhub:comments:sync` | every 15 minutes | The inbox stops updating |
| `socialhub:webhooks:prune` | daily at 03:30 | `webhook_events` grows unbounded |

Confirm what is actually registered with `php artisan schedule:list`.

## Backups

Three things, in this order of how much they hurt to lose:

1. **PostgreSQL** — posts, accounts, analytics, comments. Continuous WAL
   archiving plus daily fulls. A 24-hour-old backup means a day of lost posts.
2. **Media bucket** — objects, not the database. Enable versioning if the bucket
   supports it; deleting a media file is otherwise unrecoverable even though
   the row survives as a soft delete.
3. **Redis** — do not back it up. A lost queue means re-scheduling unpublished
   posts, which is recoverable; a corrupted queue is not.

```bash
# Logical backup, safe to run against a live database.
pg_dump "$DATABASE_URL" --format=custom --file=socialhub-$(date -u +%F).dump

# Restore
pg_restore --clean --if-exists --dbname="$DATABASE_URL" socialhub-2026-01-01.dump
```

A backup you have not restored from is a hypothesis. Test the restore quarterly.

## Monitoring

There is **no public readiness endpoint**, by design. `/up` is the only probe:
it reports that the process booted and discloses nothing about the database,
the queue or the configuration. This application deliberately refuses to expose
diagnostic routes over HTTP, and a probe revealing component health is a
disclosure risk.

Readiness and capability reporting happen on the host instead:

```bash
php artisan socialhub:doctor
php artisan socialhub:doctor --json | jq -e '.healthy'
```

`socialhub:doctor` reports per-provider configuration (present/missing, never a
value), the capability matrix, and infrastructure health for the database, the
queue, the media disk and the OAuth state store. It is safe to run on a
schedule and safe to ship the JSON to an alerting pipeline:

```bash
*/15 * * * * cd /var/www/html && php artisan socialhub:doctor --json | jq -e '.healthy' >/dev/null || \
  logger -t socialhub "doctor reported unhealthy"
```

Alert on:

- `failed_jobs` count above 0 for more than a few minutes
- oldest `publishing` job older than 5 minutes (the queue is stuck)
- any `PostVariant` in `failed` that no one has retried
- `social_account` rows in `revoked` or `expired` (publishing silently stops)
- Redis memory above 85% (`noeviction` will start refusing writes)
- webhook signature rejections spiking (a provider changed its secret, or
  something is probing the endpoint)

## Troubleshooting

**A post says "Published" on one network and "Failed" on another.**
Working as designed. Each network is tracked independently. The failing card
carries the provider's own explanation; fix the content or the account and
retry that one variant.

**"Queued" forever.**
No worker, or the worker is on a different queue name. Check
`php artisan queue:monitor default:1000`.

**429s in the logs.**
The rate limiter is working. Jobs are released with the provider's `Retry-After`
and do not count as failures. If you have many accounts on one platform, the
comment sync is staggered for exactly this reason.

**"The network rejected the post" with no detail.**
`config('app.debug')` is off, which is correct in production. The technical
error is in the log; the redacted context is under
`socialhub.publishing.*`. Credentials are stripped before it is written.

**An account says `expired`.**
The refresh token lapsed. Reconnect from Social Accounts. Nothing is lost; the
published history stays.

**Analytics are lower than the platform's own dashboard.**
Some platforms count differently, and a metric a platform does not report is
absent rather than zero. The dashboard lists per-platform metric availability
for exactly this reason — check it before concluding the numbers are wrong.

## Secrets

`APP_KEY` encrypts stored OAuth tokens. **Losing it makes every connected
account unreadable** and losing it differently from a backup makes them
unrecoverable. Back it up separately from the database, in a secret manager.

Rotating `APP_KEY` requires `php artisan key:rotate`, which re-encrypts the
stored tokens. Do not simply overwrite it.
