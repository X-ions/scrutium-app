# Scrutium — Remaining Work

Enterprise influencer marketing intelligence platform: campaign management, deliverable
verification, performance analytics, scoring, and integrations — from contract to financial
decision.

**Current status:** the Scrutium application has authenticated, tenant-scoped routes, database-backed
overview and module pages, campaign/roster/deliverable workflows, scoring recalculation, report
snapshots, alert triage, integrations, settings, security headers, a nonce-based CSP, and a green
CI pipeline running Pint, Pest, Biome and the Vite build on every push.

Legend: `[ ]` todo · `[~]` implemented, awaiting runtime verification · `[x]` implemented

---

## Verification log

Last run locally on PHP 8.3.8 / Laravel 12.26.4 / Node 22.

- [x] `php artisan migrate:fresh --seed` — all 14 migrations plus `ScrutiumDemoSeeder` complete cleanly
- [x] `php artisan test` — 24 tests, 108 assertions, all passing
- [x] `vendor/bin/pint --test` — PASS on 110 files
- [x] `npx biome lint resources/js` — PASS, 0 findings
- [x] `npm run build` — succeeds; CSS 61 kB, JS 80 kB
- [x] `npm audit --omit=dev --audit-level=high` — 0 vulnerabilities
- [x] Production database is managed Postgres (Neon) via `DB_URL`, not the old `/tmp` SQLite

> Note: Composer is not on `PATH` on this machine. Run it as
> `php "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\composer.phar" <cmd>`.

---

## ✓ Already in place

- [x] Laravel 12 + Tailwind v4 + Alpine.js + Vite scaffold (TailAdmin base)
- [x] Rebranded sidebar / nav to Scrutium IA (`MenuHelper`)
- [x] Database-backed overview and all core module routes are live and runtime-verified
- [x] Light/dark theme store, English/Arabic RTL + locale switching plumbing
- [x] Vercel deployment, Neon Postgres, and CI (`ci.yml`) + migration (`migrate.yml`) workflows

---

## Goal 1 — Real data model

- [x] Design domain schema: `tenants`, `campaigns`, `influencers`, `campaign_influencer`, `deliverables`, `content_posts`, `metrics`, `scores`, `score_configs`, `reports`, `alerts`, `integrations`
- [x] Migrations + Eloquent models + relationships (14 migrations, 13 models)
- [x] Factories + `ScrutiumDemoSeeder` with two realistic demo workspaces
- [x] Enums for campaign stage, verification status, and alert severity
- [x] Money/currency + date conventions (`decimal:2` + tenant `currency`)

Deferred: a `metrics` reference table for per-tenant metric definitions — the scoring engine
reads metric keys from `score_configs.weights`.

## Goal 2 — Authentication & access control

- [x] Authentication backend with workspace registration, login, logout, session regeneration, CSRF
- [x] Tenant middleware and role-based operational access
- [x] Campaign creation, roster assignment, deliverables, evidence, approval/rejection, audit trail
- [x] Creator sourcing/vetting, content monitoring, metrics, scoring config/recalculation
- [x] Report snapshots, alert triage/subscriptions, integrations, workspace settings
- [x] Session encryption, debug endpoint removed, protected production session config

## Goal 3 — Real module pages

- [x] Overview, campaigns, influencers, deliverables, content, performance, scoring, reports,
      alerts, integrations, settings — all backed by live controllers and tenant-scoped queries

## Goal 4 — English UI & RTL layout

- [x] English-only UI copy; direction selector limited to English (LTR) and Arabic (RTL)
- [x] Logical properties applied across surviving components; the notification dropdown and the
      overview table header were corrected (`ltr:`/`rtl:` mirroring, `text-start`)
- [ ] **Browser-level RTL QA is still unverified.** Static analysis is clean, but no automated test
      renders a page in `dir="rtl"`, and there is no browser test suite. Needs manual passes over
      every page at both directions, or a Dusk/Playwright suite.

## Goal 5 — Cleanup & dead code

- [x] Removed the 13 unrouted TailAdmin demo pages, the 30 components and 38 view classes only they
      used, the 9 orphaned JS modules, and 778 lines of dead third-party CSS
- [x] Removed 9 now-unused npm packages (ApexCharts, Flatpickr, FullCalendar, jsVectorMap, Swiper,
      Prism.js, Popper, Floating UI, temporal-polyfill). Bundle: CSS 152 kB → 61 kB, JS 1265 kB → 80 kB
- [x] `DashboardController` returns the live overview view; obsolete `SidebarController` removed
- [x] `/hello`, `tailwind-laravel.png`, and `api/debug.php` removed
- [x] CD resolved: Vercel Git integration is the deployment path, so the placeholder `cd.yml` was deleted
- [ ] Prune the remaining zero-usage app-owned CSS utilities (`tableCheckbox`, `taskCheckbox`,
      `.task`, `form-check-input`, `social-button`, `edit-button`, `docs-*`, `nav-icon-item*`,
      `.simplebar-*`) and the stale `ecommerce`/`ui-elements` icon entries in `MenuHelper`

## Goal 6 — Deploy & production readiness

- [x] Managed Postgres (Neon) wired through `DB_URL`, with a custom `PostgresConnection` that
      preserves boolean bindings (Laravel's `prepareBindings()` casts bools to int, which PostgreSQL
      rejects for `boolean` columns)
- [x] Real session + encrypted cookie/database-capable session configuration
- [x] Baseline security headers, HSTS, and a **nonce-based CSP** whose `script-src` has no
      `'unsafe-inline'`; all inline event handlers converted to Alpine; `trustProxies` configured so
      HSTS is actually emitted behind the Vercel proxy
- [x] `/health/db` no longer leaks the raw exception message to anonymous callers
- [x] Evidence disk is configurable via `EVIDENCE_DISK` and logs a warning if it resolves to a
      non-durable disk in production
- [ ] **Deliverable evidence storage still needs credentials.** `league/flysystem-aws-s3-v3` is
      installed and the code is disk-agnostic, but until `EVIDENCE_DISK=s3` plus `AWS_*` variables
      are set, uploads land on Vercel's ephemeral `/tmp` and are lost on the next cold start.
      Action: create a bucket, set the AWS env vars, flip `EVIDENCE_DISK`.
- [ ] Queue worker / scheduler. Deliberately not built yet: there are no long-running jobs, no
      integration sync code, and scoring/report generation are fast enough to stay synchronous. On
      Vercel, cron frequency is also plan-capped (Hobby = once/day), so a queue would currently add
      latency rather than remove it. Revisit when a real sync or export job exists.
- [ ] Drop `'unsafe-eval'` from `script-src`. Requires switching to the `@alpinejs/csp` Alpine build
      plus browser QA of every `x-data` / `x-on` expression in the app. The test suite cannot catch
      a broken Alpine directive.

## Goal 7 — Quality gates

- [x] CI runs PHP 8.3 (Composer validate, syntax check, Pint, Pest), Node 22 (Biome lint, Vite
      build), and dependency audits
- [x] `composer lint` / `composer format` scripts; codebase normalized so the Pint gate passes
- [x] `npm run lint` / `npm run lint:fix` via Biome with a checked-in `biome.json`
- [x] Deployed-application smoke test job (hits `/signin` and asserts `/health/db` reports `ok`) on
      pushes to `main`
- [x] Authentication, tenant-isolation, scoring, verification-rule, security-header and CSP
      regression tests
- [ ] **38 Composer advisories remain** in `guzzlehttp/guzzle`, `guzzlehttp/psr7` and
      `laravel/framework`. Guzzle was updated to 7.15.5 and psr7 to 2.13.1, which cleared 4, but the
      rest need a `laravel/framework` upgrade. The CI audit step is `continue-on-error` so the rest
      of the gate stays meaningful; remove that override once the advisories are cleared.

---

## Milestones

1. **M1 — Foundations:** schema, models, seeders, auth, tenancy → *done*
2. **M2 — Core loop:** campaigns → roster → deliverables → verification → audit log → *done*
3. **M3 — Intelligence:** content monitoring, performance analytics, scoring engine → *done*
4. **M4 — Scale:** reports, alerts, integrations, roles & permissions → *done*
5. **M5 — Hardening:** prod DB/session/storage, CI green, RTL QA complete → *storage credentials and
   browser RTL QA outstanding*

## Definition of done (functional app)

- [x] A user can sign up, log in, and only see their tenant's data
- [x] Campaigns can be created, staffed, and tracked through their lifecycle
- [x] Deliverables accept evidence and move through a verifiable status
- [x] Performance and scoring numbers come from the database, not Blade literals
- [x] Reports export, alerts fire, integrations show real platform health
- [x] CI runs tests on every PR and deploys land without manual steps
- [ ] Submitted evidence survives a serverless cold start (blocked on bucket credentials)
