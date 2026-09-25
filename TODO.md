# Scrutium — Remaining Work

Enterprise influencer marketing intelligence platform: campaign management, deliverable
verification, performance analytics, scoring, and integrations — from contract to financial
decision.

**Current status:** the Scrutium application now has authenticated, tenant-scoped routes, database-backed overview and module pages, campaign/roster/deliverable workflows, scoring recalculation, report snapshots, alert triage, integrations, settings, baseline response security headers, real CI checks, and a working frontend build. **Runtime Laravel verification is still pending because PHP/Composer are unavailable in this environment.**

Legend: `[ ]` todo · `[~]` implemented, awaiting runtime verification · `[x]` implemented

---

## Verification log

Runtime verification needs PHP 8.3+ and Composer installed locally.

- [x] **Static checks green** — node verifier confirms balanced brackets, every `App\*` import resolves to a file, every `$fillable`/cast column exists in the migrations, and every `HasFactory` model has a factory (21 tables / 179 columns / 13 models cross-checked)
- [x] `.env.example` standardised on SQLite and `database/database.sqlite` created, so `migrate` can run
- [x] `npm run build` succeeds — Vite 7.1.3, 190 modules transformed → `public/build/` (Tailwind v4 CSS 151 kB, app JS 1.26 MB)
- [x] Frontend production build revalidated after workflow/security changes
- [ ] ⛔ **BLOCKED — PHP 8.3+ / Composer are unavailable in the current shell.** The code, routes, and views are implemented, but `php artisan migrate:fresh --seed` must be run in an environment with PHP before runtime acceptance.
- [ ] `php artisan test` (Pest) green — includes domain tests, workflow tests, and authentication/tenant tests

---

## ✓ Already in place

- [x] Laravel 12 + Tailwind v4 + Alpine.js + Vite scaffold (TailAdmin base)
- [x] Rebranded sidebar / nav to Scrutium IA (`MenuHelper`)
- [x] Database-backed overview and all core module routes are live; PHP runtime acceptance remains pending
- [x] Light/dark theme store, English/Arabic RTL + locale switching plumbing
- [x] Docker/Sail, Vercel config, real CI checks, and frontend CD build (deployment targets remain provider-specific)

---

## Goal 1 — Real data model (blocker)

- [~] Design domain schema: `tenants`, `campaigns`, `influencers`, `campaign_influencer` (roster/assignments), `deliverables`, `content_posts`, `metrics`, `scores`, `score_configs`, `reports`, `alerts`, `integrations`
- [~] Create migrations + Eloquent models + relationships (14 migrations, 13 models)
- [~] Add factories + seeders with realistic demo data (`ScrutiumDemoSeeder` — 2 workspaces, replaces hardcoded rows)
- [~] Add enums for statuses (campaign stage, verification status, alert severity)
- [~] Define money/currency + date conventions once, use everywhere (`decimal:2` + tenant `currency`)

Deferred from this goal: a `metrics` reference table for per-tenant metric definitions — the
scoring engine currently reads metric keys from `score_configs.weights`.


## Goal 2 — Authentication & access control

- [x] Authentication backend with workspace registration, login, logout, session regeneration, and CSRF-protected forms
- [x] Authenticated tenant middleware and role-based operational access
- [x] Database-backed overview and all core module pages
- [x] Campaign creation, roster assignment, deliverable creation, evidence submission, approval/rejection, and audit trail
- [x] Creator sourcing/vetting, content monitoring, performance metrics, scoring configuration/recalculation
- [x] Versioned report snapshots, alert triage/subscriptions, integration management, and workspace settings
- [x] Session encryption, removed debug endpoint, and protected production session configuration

## Goal 3 — Real module pages

All listed Scrutium modules are backed by live controllers, tenant-scoped queries, and dedicated views.

- [x] Overview: driven from tenant-scoped aggregation queries
- [x] Campaigns: list, detail, roster/assignments, campaign creation, budget allocation
- [x] Influencers: intelligence list, vetting pipeline, creator sourcing
- [x] Deliverables: accountability table, evidence upload, verification status, audit log
- [x] Content: monitoring feed, provenance/integrity status
- [x] Performance: spend, reach, engagements, CPE, and campaign efficiency
- [x] Scoring: configurable weights and data-driven recalculation
- [x] Reports: versioned snapshots, freeze/publish, JSON download
- [x] Alerts: issue tracking, acknowledgement, resolution, subscriptions
- [x] Integrations: provider configuration, encrypted credentials, connect/disconnect
- [x] Settings: workspace and team role management
- [x] Controllers + Form validation for core module workflows

## Goal 4 — English UI & RTL layout

- [x] Keep user-facing copy in English per the current product scope; no translation dictionaries are required
- [x] Limit the direction selector to English (LTR) and Arabic (RTL)
- [ ] Complete browser-level RTL QA across every new component (chevrons, tables, drawers)

## Goal 5 — Cleanup & dead code

- [x] DashboardController returns the live `pages.dashboard.overview` view
- [x] Removed the obsolete SidebarController and shared placeholder view
- [x] Scoping middleware resolves the authenticated user's tenant for every protected request
- [ ] Decide whether to wire or remove the legacy TailAdmin demo pages (charts, tables, form-elements, ui-elements, calendar, blank); they currently have no routes
- [x] `/hello` route and `tailwind-laravel.png` are absent
- [x] `.kilo` duplicate worktree is ignored rather than deleted
- [x] `api/debug.php` and the public `/debug` route removed

## Goal 6 — Deploy & production readiness

- [x] Standardize DB config — README, `.env.example` and `config/database.php` now all default to SQLite
- [ ] Wire Vercel to a managed database (Neon/PlanetScale/RDS) — SQLite in `/tmp` is ephemeral
- [x] Real session + encrypted cookie/database-capable session configuration (not `array`)
- [ ] Queue worker / scheduler for syncs, scoring, report generation
- [ ] Object storage for deliverable evidence + exports (S3)
- [x] Plaintext stack-trace error handler removed; Laravel's standard exception handling is active
- [x] Login, registration, and logout endpoints are throttled and CSRF protected
- [x] Added baseline response security headers (nosniff, frame denial, referrer policy, permissions policy, and HSTS on HTTPS)
- [ ] Expand CSP hardening with a nonce-based policy after the application’s inline-script policy is finalized

## Goal 7 — Quality gates

- [x] CI runs PHP 8.3, Composer metadata/PHP syntax checks, Pest, Node 22, the Vite build, and dependency audits
- [x] Authentication and tenant-isolation feature tests added; runtime execution awaits PHP
- [x] Added scoring recalculation, verification-rule, evidence-required, and security-header regression tests; runtime execution awaits PHP
- [x] Composer metadata and PHP syntax are checked in CI
- [ ] Enable a full Pint formatting check after the existing codebase is normalized
- [ ] Add a JS lint/typecheck script after selecting a project linter (no new dependency was introduced)
- [ ] Replace provider-specific CD deployment placeholders with the chosen staging/production target
- [ ] Add a deployed-application smoke test to CI

---

## Milestones

1. **M1 — Foundations:** schema, models, seeders, auth, tenancy → *app is login-able and data persists*
2. **M2 — Core loop:** campaigns → roster → deliverables → verification → audit log
3. **M3 — Intelligence:** content monitoring, performance analytics, scoring engine
4. **M4 — Scale:** reports, alerts, integrations, roles & permissions
5. **M5 — Hardening:** prod DB/session/storage, CI green, RTL QA complete

## Definition of done (functional app)

- A user can sign up, log in, and only see their tenant's data
- Campaigns can be created, staffed, and tracked through their lifecycle
- Deliverables accept evidence and move through a verifiable status
- Performance and scoring numbers come from the database, not Blade literals
- Reports export, alerts fire, integrations show real platform health
- CI runs tests on every PR and deploys land without manual steps
