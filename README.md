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
- **Frontend:** Blade, Tailwind CSS v4, Alpine.js, Vite
- **Charts / maps:** ApexCharts and related dashboard components (from UI base)
- **Deploy:** Vercel (serverless PHP via `vercel-php`) + optional Docker / Laravel Sail

---

## Requirements

- PHP 8.3+
- Composer
- Node.js 18+ (22.x recommended) and npm
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

Starts Laravel, Vite HMR, queue worker, and log tail.

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

## Deploy (Vercel)

This repo includes `vercel.json` and `api/index.php` for **vercel-php**.

1. Import the GitHub repo in Vercel.
2. Framework Preset: **Other** · Output Directory: **`public`** · Node **22.x**.
3. Set environment variables (Production):

| Variable | Example |
|----------|---------|
| `APP_NAME` | `Scrutium` |
| `APP_ENV` | `production` |
| `APP_KEY` | `base64:...` (from `php artisan key:generate --show`) |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-app.vercel.app` |
| `LOG_CHANNEL` | `stderr` |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `DB_CONNECTION` | `pgsql` |
| `DATABASE_URL` | `postgres://...` (Neon branch URL) |
| `FILESYSTEM_DISK` | `s3` |
| `AWS_BUCKET` | `scrutium-production` |
| `AWS_ENDPOINT` | `https://<your-neon-storage-endpoint>` |
| `AWS_ACCESS_KEY_ID` | `...` |
| `AWS_SECRET_ACCESS_KEY` | `...` |
| `AWS_DEFAULT_REGION` | `us-east-1` |
| `AWS_URL` | `https://<your-neon-storage-endpoint>/<bucket-name>` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |

4. Redeploy after saving env vars.

The workspace-preferences migration adds the settings fields to existing tenant databases. Vercel's startup migration check runs pending migrations when `SCRUTIUM_AUTO_MIGRATE=true` and `DB_CONNECTION=pgsql`; the database user must be permitted to alter the schema. If automatic migrations are disabled, run `php artisan migrate --force` as part of deployment.

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
├── app/                 # Controllers, models, middleware
├── bootstrap/
├── config/
├── database/
├── public/              # Web root + Vite build output
├── resources/
│   ├── css/             # Tailwind v4
│   ├── js/
│   └── views/           # Blade (dashboards, layout, components)
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
