# Scrutium — Remaining Work

Enterprise influencer marketing intelligence platform: campaign management, deliverable
verification, performance analytics, scoring, and integrations — from contract to financial
decision.

**Current status:** the Scrutium application has authenticated, tenant-scoped routes, database-backed
overview and module pages, campaign/roster/deliverable workflows, scoring recalculation, report
snapshots, alert triage, integrations, settings, security headers, a nonce-based CSP, zero known
dependency advisories, and a green CI pipeline running Pint, Pest, Biome and the Vite build.

Legend: `[ ]` todo · `[~]` implemented, awaiting verification · `[x]` implemented

---

## Verification log

Last run locally on PHP 8.3.8 / Laravel 12.69.2 / Node 22.

- [x] `php artisan migrate:fresh --seed` — all 14 migrations plus `ScrutiumDemoSeeder` complete cleanly
- [x] `php artisan test` — 52 tests, 287 assertions, all passing
- [x] `vendor/bin/pint --test` — PASS on 116 files
- [x] `npm run lint` (Biome) — PASS, 0 findings
- [x] `npm run build` — succeeds; **CSS 56 kB, JS 84 kB** (was 152 kB / 1265 kB)
- [x] `composer audit` — **no security vulnerability advisories found**
- [x] `npm audit --omit=dev --audit-level=high` — 0 vulnerabilities

> Measure CSS after `php artisan view:clear`. `app.css` scans compiled Blade caches, so a warm cache
> re-emits utilities for deleted classes and inflates the number by ~15 kB. CI is unaffected.
>
> Composer is not on `PATH` on this machine. Run it as
> `php "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\composer.phar" <cmd>`.

---

## Just shipped — "How it works" tour

- [x] Clickable overlay tour (`resources/js/components/tour.js` + `components/common/tour.blade.php`):
      spotlight cut-out, Back/Next/Skip, progress dots, keyboard nav (Esc, arrows), dismissable at
      any point, and a `prefers-reduced-motion` guard
- [x] Launches from the header ✦ button, from the guide page, and automatically on a user's first
      dashboard visit (localStorage, so it never nags twice)
- [x] `/how-it-works` — the permanent written version, numbered and linked, for anyone who
      dismissed the overlay
- [x] **Role-aware**: steps carry an `audience` (`any` / `operator` / `admin`) and are filtered
      server-side, so a Viewer is never told to click a button the `operate` middleware will refuse,
      and only admins are pointed at Settings
- [x] Cross-page continuity: a step whose route differs from the current one navigates and resumes
      via a sessionStorage hand-off that records the target route, so a stale entry cannot force the
      tour open on an unrelated page
- [x] 16 tests covering the guest guard, step ordering, route resolution, the role split, tour-copy
      claims vs the domain enums, and the `@js()` HTML-attribute escaping that would otherwise break
      Alpine on every page

### Bugs the tour surfaced and fixed

- [x] **Rejected deliverables could be resubmitted.** `DeliverableController::submit()` only guarded
      `Approved`, so a crafted POST resurrected a rejected row and cleared its rejection reason. The
      view already hid the form, so the intent was clear; the controller now guards both.
- [x] **Seeded demo data was un-approvable.** The seeder called `markSubmitted()` with no argument,
      leaving `evidence_path` null, and `approve()` refuses rows without evidence — so the demo
      worklist looked full but every "Submitted" row dead-ended on a 422. The seeder now writes a
      plausible evidence path.
- [x] **Submitted evidence and rejection reasons were never displayed.** `evidence_path` and
      `rejection_reason` appeared nowhere in any view, so a creator who submitted a file never saw it
      again and nobody could see why something was rejected. `Deliverable::evidenceForDisplay()` now
      resolves the value — external URL, stored file, or honestly reports it as unavailable rather
      than emitting a dead link — and the deliverable page renders it alongside the rejection reason,
      which also appears on the relevant audit event. 10 tests cover the four evidence states.
- [x] Emoji removed from the tour and guide. Steps are identified by number and inline SVG rather
      than emoji bubbles.

---


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

Deferred: a `metrics` reference table for per-tenant metric definitions — the scoring engine reads
metric keys from `score_configs.weights`. Revisit when metrics become user-definable.

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
- [x] Logical properties applied across all surviving views; `RtlComplianceTest` now fails the build
      if a physical-direction utility (`ml-`, `left-`, `text-right`, …) reappears without an
      `ltr:`/`rtl:` wrapper
- [x] Fixed real defects: notification dropdown flew off-screen in RTL, dashboard table header used
      `text-left`, status dot and error-page centring used physical offsets
- [ ] **Browser-level RTL QA is still unverified.** The regression test is static analysis only — it
      proves the class names are logical, not that the rendered layout is correct. Needs manual passes
      over every page in both directions, or a Dusk/Playwright suite.

## Goal 5 — Cleanup & dead code

- [x] Removed the 13 unrouted TailAdmin demo pages, the 35 components and 38 view classes only they
      used, the 9 orphaned JS modules, and ~870 lines of dead third-party CSS
- [x] Removed 9 now-unused npm packages (ApexCharts, Flatpickr, FullCalendar, jsVectorMap, Swiper,
      Prism.js, Popper, Floating UI, temporal-polyfill). **Bundle: CSS 152 → 52 kB, JS 1265 → 80 kB**
- [x] Pruned the remaining dead app-owned CSS (`tableCheckbox`, `taskCheckbox`, `form-check-input`,
      `social-button`, `edit-button`, `docs-*`, `nav-icon-item*`, `.simplebar-*`, and 11 unused
      `menu-dropdown-*` / `menu-item-arrow-*` `@utility` blocks). 8 live utilities remain, no duplicates
- [x] Removed 5 orphaned inline SVG entries from `MenuHelper`; all 10 remaining icons are referenced
- [x] `DashboardController` returns the live overview view; obsolete `SidebarController` removed
- [x] `/hello`, `tailwind-laravel.png`, and `api/debug.php` removed
- [x] CD resolved: Vercel Git integration is the deployment path, so the placeholder `cd.yml` was deleted
- [x] `AGENTS.md` corrected where it had drifted: the `components/svg/` + `<x-svg.*>` convention (icons
      are inline strings in `MenuHelper`), the non-existent `routes/api.php`, the removed
      `<x-common.component-card>`, and utilities that no longer exist

## Goal 6 — Deploy & production readiness

- [x] Managed Postgres (Neon) wired through `DB_URL`, with a custom `PostgresConnection` that preserves
      boolean bindings (Laravel's `prepareBindings()` casts bools to int, which PostgreSQL rejects for
      `boolean` columns)
- [x] Real session + encrypted cookie/database-capable session configuration
- [x] Baseline security headers, HSTS, and a **nonce-based CSP** whose `script-src` has no
      `'unsafe-inline'`; all inline event handlers converted to Alpine; `trustProxies` configured so
      HSTS is actually emitted behind the Vercel proxy
- [x] `/health/db` no longer leaks the raw exception message to anonymous callers
- [x] Evidence disk is configurable via `EVIDENCE_DISK` and logs a warning if it resolves to a
      non-durable disk in production
- [ ] **Deliverable evidence storage still needs credentials.** `league/flysystem-aws-s3-v3` is
      installed and the code is disk-agnostic, but until `EVIDENCE_DISK=s3` plus the `AWS_*` variables
      are set, uploads land on Vercel's ephemeral `/tmp` and are lost on the next cold start.
      Action: create a bucket, set the AWS env vars, flip `EVIDENCE_DISK`.
- [ ] Queue worker / scheduler. Deliberately not built yet: there are no long-running jobs, no
      integration sync code, and scoring/report generation are fast enough to stay synchronous. On
      Vercel, cron frequency is also plan-capped (Hobby = once/day), so a queue would currently add
      latency rather than remove it. Revisit when a real sync or export job exists.
- [ ] Drop `'unsafe-eval'` from `script-src`. Requires switching to the `@alpinejs/csp` Alpine build
      plus browser QA of every `x-data` / `x-on` expression. The test suite cannot catch a broken
      Alpine directive, so this is not safe to do blind.

## Goal 7 — Quality gates

- [x] CI runs PHP 8.3 (Composer validate, syntax check, Pint, Pest), Node 22 (Biome lint, Vite build),
      and dependency audits
- [x] `composer lint` / `composer format` scripts; codebase normalized so the Pint gate passes
- [x] `npm run lint` / `npm run lint:fix` via Biome with a checked-in `biome.json`
- [x] Deployed-application smoke test job (hits `/signin`, asserts `/health/db` reports `ok`) on
      pushes to `main`
- [x] Regression tests for auth, tenant isolation, scoring, verification rules, security headers, CSP
      and RTL compliance — 26 tests total
- [x] **All 42 Composer advisories cleared** by upgrading `laravel/framework` 12.26.4 → 12.69.2,
      `guzzlehttp/guzzle` → 7.15.5, `guzzlehttp/psr7` → 2.13.1, `pestphp/pest` → 4.7.8,
      `phpunit/phpunit` → 12.5.33, `psy/psysh` → 0.12.24 and `symfony/yaml` → 7.4.18. The `composer
      audit` gate is blocking again.

---

## Milestones

1. **M1 — Foundations:** schema, models, seeders, auth, tenancy → *done*
2. **M2 — Core loop:** campaigns → roster → deliverables → verification → audit log → *done*
3. **M3 — Intelligence:** content monitoring, performance analytics, scoring engine → *done*
4. **M4 — Scale:** reports, alerts, integrations, roles & permissions → *done*
5. **M5 — Hardening:** prod DB/session/storage, CI green, RTL QA complete → *evidence credentials and
   browser RTL QA outstanding*

## Definition of done (functional app)

- [x] A user can sign up, log in, and only see their tenant's data
- [x] Campaigns can be created, staffed, and tracked through their lifecycle
- [x] Deliverables accept evidence and move through a verifiable status
- [x] Performance and scoring numbers come from the database, not Blade literals
- [x] Reports export, alerts fire, integrations show real platform health
- [x] CI runs tests on every PR and deploys land without manual steps
- [ ] Submitted evidence survives a serverless cold start (blocked on bucket credentials)

## Suggested next features

The hardening goals above are done as far as they can be without credentials. High-value follow-ons,
roughly in dependency order:

1. **Integration sync** — `IntegrationController` stores provider config but nothing ever calls out to a
   provider. Real OAuth + a queued pull for platform metrics is the largest missing product capability,
   and it is also what would finally justify the queue worker.
2. **Per-tenant metric definitions** — closes the deferred `metrics` reference table and makes
   `score_configs.weights` user-editable rather than fixed.
3. **Evidence review UX** — the audit trail records state changes, but there is no side-by-side
   submit-vs-approve view for a verifier.
4. **Exports** — reports stream JSON from the `payload` column; a real CSV/XLSX export and scheduled
   delivery would use the storage layer that S3 evidence will provide.
5. **Browser test harness** — Dusk or Playwright would close the RTL QA gap and let UI regressions be
   caught in CI rather than by hand.
