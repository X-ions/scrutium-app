# Scrutium | X-ion, Inc.

**Enterprise influencer marketing intelligence platform.**

Campaign management, deliverable verification, performance analytics, scoring engines, and API integrations — built for brands and agencies that need end-to-end accountability from contract to financial decision.

> UI foundation is based on [TailAdmin Laravel](https://tailadmin.com/laravel) (Laravel 12 + Tailwind CSS v4 + Alpine.js). Product design, branding, and domain features are **Scrutium**.

---

## What Scrutium does

| Area | Capability |
|------|------------|
| **Overview** | Data lifecycle status, attainment performance, system integrity, efficiency leaders |
| **Campaigns** | Campaign central, roster & assignments, create-campaign wizard, budget allocation |
| **Influencers** | Influencer intelligence, pulse scoring, vetting pipeline, talent sourcing |
| **Deliverables** | Accountability table, evidence upload, verification status, audit log |
| **Content** | Real-time monitoring feed, provenance scores, engagement timeline |
| **Performance** | ROI, CPE, EMV, CTR, target vs actual, cost efficiency by platform |
| **Scoring** | Configurable metric weights, cohort logic, ML-ready scouter engine |
| **Reports** | Versioned reports, freeze cutoffs, bulk export, system version registry |
| **Alerts** | Issue tracking, subscriptions, compliance notifications |
| **Integrations** | Platform health, API management, auto-verification |
| **Security** | Activity audit log, trusted devices, active sessions, notification preferences |
| **Settings** | Workspace profile, preferences, roles & permissions, tenant overview |

### Workspace settings

Workspace owners and admins can open the workspace settings modal from the gear icon beside the workspace selector in the sidebar. It supports:

- Workspace name changes, limited to once every seven days, plus a workspace description and logo.
- Default currency and workspace language (English or Arabic/RTL).
- Email subscriptions for security alerts, compact dashboard spacing, and modal-only auto-save.
- A Pricing control that displays the workspace's current plan.

Workspace preferences are shared with workspace members. Security-alert subscriptions are saved per user. Workspace logos use the configured evidence storage disk; configure an S3-compatible disk for durable uploads on Vercel.

---

## Stack

- **Backend:** Laravel 12, PHP 8.3+
- **Frontend:** Blade, Tailwind CSS v4, Alpine.js, Vite 7
- **Mail:** Resend (SMTP) for transactional email
- **Deploy:** Vercel (serverless PHP via `vercel-php`) + optional Docker / Laravel Sail

Charts, tables, and calendar views render with plain Blade and Tailwind. The template's original demo libraries (ApexCharts, FullCalendar, Swiper, Flatpickr, jsVectorMap, Prism) were removed along with the demo pages that used them.

---

## Requirements

- PHP 8.3+
- Composer
- Node.js 22.x (pinned in `.nvmrc` and `package.json`) and npm
- SQLite (default), MySQL, or PostgreSQL

```bash
php -v && composer -V && node -v && npm -v
```

---

## Quick start (local)

```bash
git clone https://github.com/scrutium-inc/scrutium-app.git
cd scrutium-app

composer install
npm install

cp .env.example .env
php artisan key:generate

# Optional: SQLite
touch database/database.sqlite
# Or configure MySQL/PostgreSQL in .env

php artisan migrate
npm run build   # or: npm run dev
php artisan serve
```

App: [http://localhost:8000](http://localhost:8000)

### Dev all-in-one

```bash
composer run dev
```

Starts Laravel, the queue worker, log tail, and Vite HMR together.

### Tests and linting

```bash
composer test        # Pest
composer lint        # Pint style check
composer format      # Pint auto-fix
npm run lint         # Biome (resources/js)
```

---

## Docker / Laravel Sail

```bash
cp .env.example .env
# Set DB_HOST=mysql, REDIS_HOST=redis, DB_USERNAME=sail, DB_PASSWORD=password

composer install   # or via laravelsail/php84-composer image
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

---

## Email and email verification

Password resets, address verification, welcome mail, and security alerts all go through the `resend` mailer.

```env
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=no-reply@your-verified-domain.com
MAIL_FROM_NAME="Scrutium"
RESEND_KEY=re_xxxxxxxxxxxxxxxx
```

`MAIL_FROM_ADDRESS` **must** sit on a domain verified in Resend, or delivery is rejected. For the production deployment that means authenticating the sending domain in the Resend dashboard and waiting for DKIM/SPF to verify.

Set `RESEND_KEY` for **both** the Production and Preview environments in Vercel. Marking a variable for only one of them is the most common cause of "the request succeeds but no email arrives".

### Verification flow

Registration sends a verification link and the new account is signed in immediately, but a non-dismissible modal blocks the workspace until the address is confirmed. Unverified users can sign in and browse; the modal re-renders on every request, so it cannot be dismissed or routed around. Changing your email address clears `email_verified_at` and re-sends the link to the new address.

Security emails are only sent to verified addresses.

### Mail troubleshooting

| Symptom | Check |
|---|---|
| Request returns 302, no email arrives | `RESEND_KEY` set for the deploying environment |
| Resend rejects the send | Sending domain not verified, or `MAIL_FROM_ADDRESS` off-domain |
| Emails land in spam | Only `app.scrutium.com` is DKIM-signed; add a DMARC record and warm the domain |
| Wrong link host in the email | `APP_URL` must match the public origin, not the `*.vercel.app` fallback |

---

## Security notifications

A security notification system records authentication and account events, recognises returning devices, and emails the user only when something genuinely needs attention.

**Tracked events:** sign-in, sign-out, failed sign-in, new device, new browser, new OS, new location, impossible travel, high-risk location, password change, email change, MFA change, recovery change, API key create/revoke, ownership transfer, session revocation, account lockout.

**What triggers an email:**

- **Sign-ins** — only the first sign-in from a device the user has never confirmed. Trusted devices and repeat sign-ins never email.
- **Failed sign-ins** — only once attempts from a single address cross the threshold, so a single typo produces nothing.
- **Account changes** — rare and consequential, so these always notify.

All of it then passes the per-user hourly and daily ceilings, configurable under **Security → Email preferences** along with a Low/Medium/High sensitivity switch.

**Trusted device workflow.** An unrecognised sign-in mints a single-use token. The alert email carries a **This was me** action that opens a review page (browser, OS, IP, location, first seen) and confirms on POST. Mail scanners pre-fetch links, so the confirmation is deliberately not a GET. Confirming marks the device trusted and silences it permanently.

**Fingerprinting.** A SHA-256 hash over stable device characteristics. Only the components in `config/security.php` → `fingerprint.stable_components` feed the hash; volatile values like the full user-agent are excluded so a browser update does not make a known device look new.

**Audit log.** Every event is written to `security_events` with IP, location, device, session, risk score, and an event ID that appears in the email for support quoting. Rows are only ever updated in their acknowledgement columns.

### Configuration

```env
# ISO-3166 alpha-2 codes, comma separated. Empty disables the check.
SECURITY_HIGH_RISK_COUNTRIES=

# Failed sign-ins from one address before the user is emailed.
SECURITY_FAILED_LOGIN_THRESHOLD=5

# Days of audit history to keep. 0 keeps everything.
SECURITY_RETENTION_DAYS=0
```

Impossible-travel detection compares implied speed between consecutive sign-ins against `security.impossible_travel_speed_kmh` (default 900 km/h).

### Queuing

Security email is dispatched to the `security` queue via `SecurityNotificationJob` with bounded retries. `vercel.json` pins `QUEUE_CONNECTION=sync`, so on Vercel the job runs inside the request that triggered it — a PHP serverless function has no long-lived worker. On a container or queue-backed host, set a real queue connection and the job becomes a true async boundary with no code change.

Geolocation uses `ipwho.is` over HTTPS and is cached for 30 days per IP. If the lookup fails the event is still recorded, just without location data.

---

## Deploy (Vercel)

This repo includes `vercel.json` and `api/index.php` for **vercel-php**.

1. Import the GitHub repo in Vercel.
2. Framework Preset: **Other** · Output Directory: **`public`** · Node **22.x**.
3. Set environment variables for **both Production and Preview**:

| Variable | Example |
|----------|---------|
| `APP_NAME` | `Scrutium` |
| `APP_ENV` | `production` |
| `APP_KEY` | `base64:...` (from `php artisan key:generate --show`) |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://app.scrutium.com` |
| `LOG_CHANNEL` | `stderr` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `DB_CONNECTION` | `pgsql` |
| `DATABASE_URL` | `postgres://...` (Neon branch URL) |
| `MAIL_MAILER` | `resend` |
| `MAIL_FROM_ADDRESS` | `no-reply@app.scrutium.com` |
| `MAIL_FROM_NAME` | `Scrutium` |
| `RESEND_KEY` | `re_...` |
| `FILESYSTEM_DISK` | `s3` |
| `AWS_BUCKET` | `scrutium-production` |
| `AWS_ENDPOINT` | `https://<your-neon-storage-endpoint>` |
| `AWS_ACCESS_KEY_ID` | `...` |
| `AWS_SECRET_ACCESS_KEY` | `...` |
| `AWS_DEFAULT_REGION` | `us-east-1` |
| `AWS_URL` | `https://<your-neon-storage-endpoint>/<bucket-name>` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |

4. Redeploy after saving env vars.

`APP_URL` must be the public origin. Leaving it on the `*.vercel.app` fallback produces verification and password-reset links pointing at the wrong host.

Most of the non-secret values above also live in `vercel.json` under `env`, so they apply automatically. `RESEND_KEY`, `APP_KEY`, and the database and storage credentials must be set in the Vercel dashboard.

### Migrations on deploy

`SCRUTIUM_AUTO_MIGRATE=true` runs pending migrations on boot, guarded by a file lock so concurrent cold starts do not race. The check tests for every table the app needs, not just the first migration, so a database that already exists still picks up tables added later. The database user must be permitted to alter the schema. If automatic migrations are disabled, run `php artisan migrate --force` as part of deployment.

### Production storage with Neon + S3-compatible object storage

The app is configured for a managed object store in production, since the local Laravel `public` disk is not persisted on Vercel or serverless deployments.

Use the Neon Storage Data API or any S3-compatible bucket for uploaded documents. Configure the app with the generated S3 endpoint and credentials, then set:

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=scrutium-production
AWS_ENDPOINT=https://<your-neon-storage-endpoint>
AWS_URL=https://<your-neon-storage-endpoint>/<your-bucket>
AWS_USE_PATH_STYLE_ENDPOINT=true
```

This keeps deliverable evidence and other uploaded files accessible even when the app is deployed to production and the filesystem is ephemeral.

> Serverless note: writable paths use `/tmp`. For production data, prefer Neon Postgres for the database and an S3-compatible bucket for document uploads instead of local files or SQLite.

---

## Project structure (high level)

```
scrutium-app/
├── api/                 # Vercel PHP entry (index.php)
├── app/
│   ├── Enums/           # UserRole
│   ├── Http/            # Controllers, middleware
│   ├── Jobs/            # Queued work (security notifications)
│   ├── Models/          # Domain + security models
│   ├── Notifications/   # Mail notifications
│   ├── Providers/       # AppServiceProvider: rate limits, auto-migrate
│   ├── Services/
│   │   └── Security/    # Fingerprinting, event detection, notification policy
│   └── Support/         # TenantContext
├── bootstrap/
├── config/              # Includes security.php
├── database/migrations/
├── public/              # Web root + Vite build output
├── resources/
│   ├── css/             # Tailwind v4
│   ├── js/
│   └── views/
│       ├── emails/      # Transactional + security email templates
│       └── pages/       # Route views (dashboards, auth, security)
├── routes/web.php
├── vercel.json
└── .github/workflows/   # CI/CD
```

---

## Roadmap (from wireframes)

> Live, actionable checklist of what's still missing: see **[TODO.md](./TODO.md)**.

1. Rebrand UI (logo, nav labels, colors) → **Scrutium**
2. Overview dashboard (data lifecycle, attainment, system integrity)
3. Campaign Central + create-campaign flow
4. Influencer Intelligence + scoring
5. Deliverable Accountability + verification
6. Content Monitoring + performance analytics
7. Reporting, alerts, integrations, roles & permissions

---

## License

Application code and Scrutium product work: **MIT** (see repository license).

UI components derive from TailAdmin Laravel; respect [TailAdmin licensing](https://tailadmin.com/license) for the original template assets where applicable.

---

**Scrutium** — from contract to decision, with verification in between.
