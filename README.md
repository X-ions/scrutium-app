# Scrutium

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
| **Settings** | Roles & permissions, tenant overview |

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
| `SESSION_DRIVER` | `cookie` |
| `CACHE_STORE` | `array` |
| `QUEUE_CONNECTION` | `sync` |
| `DB_CONNECTION` | `sqlite` |

4. Redeploy after saving env vars.

> Serverless note: writable paths use `/tmp`. For production data, plan a managed database (e.g. Neon, PlanetScale, RDS) instead of ephemeral SQLite.

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
