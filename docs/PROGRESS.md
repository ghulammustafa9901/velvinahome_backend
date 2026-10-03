# Progress

Updated continuously (spec §0.2 rule 6). A milestone is only `done` when the app boots,
migrations run, and the whole test suite passes.

**Current work:** **Single-store conversion** — multi-tenancy is being removed (owner decision
2026-10-03). Plan: [docs/plans/remove-multi-tenancy.md](plans/remove-multi-tenancy.md); status in the
section below. Phase 7 is built and waits for owner sign-off and the hosting decision.
**Test suite:** 394 passing on real PostgreSQL 17, Redis and Typesense 30
(architecture, unit, feature, isolation, multi-process concurrency); deploy scripts 10/10.
Larastan level 8 clean, Pint clean. platform-web: ESLint, strict typecheck, Vitest, `next build`, 22 Playwright E2E
tests on the production build.

---

## Phase 0 — Planning & Architecture

Done. Report: [docs/phase-0-checkpoint.md](phase-0-checkpoint.md). The owner delegated the
environment and technical decisions (ADR-0009) and Phase 1 proceeded.

## Phase 1 — Platform Foundation

Plan: [docs/plans/phase-1.md](plans/phase-1.md).

| # | Milestone | Status | Notes |
|---|---|---|---|
| 1.1 | Laravel app, module skeleton, quality gates | done | Modules autoloaded via root PSR-4 (ADR-0011); architecture tests enforce boundaries, thin callers, strict types, no float money |
| 1.2 | Landlord database and tenancy core | done | All landlord tables; host + domain-kind resolution; unknown host 404; shared/dedicated topology; encrypted credentials; JSON log channel with `tenant_id` + `request_id` |
| 1.3 | Redis, Horizon, scheduler, outbox | done, with gaps | Tag-scoped cache (ADR-0012); tenant-aware jobs fail loudly without context; scheduler fan-out; transactional outbox. **Gaps:** Horizon cannot run on Windows (no `pcntl`) — use `queue:work` locally; failed-job alerting not wired; no dedicated rate-limit test |
| 1.4 | Tenant lifecycle commands | done | `company:create`, `tenant:create` (resumable steps), suspend/activate, export (`pg_dump`), delete (confirm + fresh backup), `tenants:migrate` (one transaction per tenant, proven rollback), `platform:seed-demo`. Seeding of tax/shipping/email templates/pages waits for those modules |
| 1.5 | Identity | done | Three guards/tables; Sanctum customer tokens; role matrix; mandatory TOTP 2FA (Filament MFA, proven by test); Argon2id; HIBP breach check; throttling + lockout; staff, customer and platform login audits; append-only activity log |
| 1.6 | Filament shells | done | Tenant admin per brand host (staff management); platform panel (companies, brands, provision/suspend/activate, support access); impersonation and brand switcher via single-use hand-off tokens (ADR-0013). Filament Shield not used — permission checks live in Actions and resources |
| 1.7 | Settings and feature flags | done | Encrypted settings, cached per tenant; plan defaults + tenant overrides; `GET /api/v1/storefront/config`; `feature:` middleware → `403 feature_disabled` |
| 1.8 | Tenant isolation suite | done (search pending) | 9 of 10 channels proven, including sibling brands of one company and dedicated topology. Search channel arrives with Typesense in Phase 2 |

## Phase 2 — Catalog, Media, Inventory, Search

Plan: [docs/plans/phase-2.md](plans/phase-2.md) · Report: [docs/phase-2-checkpoint.md](phase-2-checkpoint.md)

| # | Milestone | Status |
|---|---|---|
| 2.1 | Seo + Catalog core | done |
| 2.2 | Inventory ledger (incl. multi-process concurrency tests) | done |
| 2.3 | Search (Typesense 30, per-tenant collections and synonym sets) | done — ADR-0014 |
| 2.4 | Storefront API: catalog, search, redirects; error envelope | done |
| 2.5 | Admin: catalog and inventory screens | done |
| 2.6 | Media: safe uploads, WebP conversions | done (local disk until MinIO/R2) |

## Phase 3 — Commerce Core

Plan: [docs/plans/phase-3.md](plans/phase-3.md) · Report: [docs/phase-3-checkpoint.md](phase-3-checkpoint.md) · ADR-0015

Owner decision: **COD + manual bank/wallet transfer** at launch; email provider open.

| # | Milestone | Status |
|---|---|---|
| 3.1 | Tax, Shipping, Customers (address book) | done |
| 3.2 | Pricing pipeline and coupons | done |
| 3.3 | Carts (guest token, customer, merge) | done |
| 3.4 | Payments: methods, attempts, ledger, manual transfer review, webhook intake | done (no online gateway — owner decision) |
| 3.5 | Orders: state machine, COD confirmation, shipments, RTO, returns, refunds, notes | done |
| 3.6 | Checkout: quote, idempotent placement | done |
| 3.7 | Transactional emails via outbox | done (Laravel mailer; provider is configuration) |
| 3.8 | Admin: orders, coupons, shipping, tax, payment methods, emails | done |

## Phase 4 — API Stabilization

Plan: [docs/plans/phase-4.md](plans/phase-4.md) · Report: [docs/phase-4-checkpoint.md](phase-4-checkpoint.md) · ADR-0016 ·
Contract: [docs/api/openapi-v1.json](api/openapi-v1.json)

**Storefront API v1 is frozen** — additive changes only; enforced by `api:contract --check` in CI.

| # | Milestone | Status |
|---|---|---|
| 4.1 | Conventions by infrastructure (cache policy, ETags, rate limits, Retry-After) | done |
| 4.2 | Customer account (profile, password change, recovery) | done |
| 4.3 | Checkout shipping methods; payments endpoint deferred (additive) | done |
| 4.4 | Content: pages, menus, sitemap data | done |
| 4.5 | Reviews and wishlist (flag-gated) | done |
| 4.6 | OpenAPI finalized | done |
| 4.7 | Contract tests + drift guard | done |
| 4.8 | Generated TypeScript client (platform-web) | done |
| 4.9 | Query budgets for hot endpoints | done |
| 4.10 | v1 freeze | done |

## Phase 5 — Next.js Storefront

Plan: [docs/plans/phase-5.md](plans/phase-5.md) · Report: [docs/phase-5-checkpoint.md](phase-5-checkpoint.md) · ADR-0017

| # | Milestone | Status |
|---|---|---|
| 5.1 | Monorepo packages: ui, theme-engine, commerce-core, sections; default theme | done |
| 5.2 | Tenant resolution by host, storefront config, unknown host 404 | done |
| 5.3 | Pages: home, listing, category, collection, PDP, search, content pages | done |
| 5.4 | Cart, one-page checkout (COD + manual transfer), order confirmation, receipt upload | done |
| 5.5 | Account: sign in/up, recovery, orders, addresses, profile, wishlist, reviews | done |
| 5.6 | BFF: sealed cookies, server actions, signed service calls | done |
| 5.7 | SEO layer: metadata, JSON-LD, sitemap, robots, noindex rules | done |
| 5.8 | ISR + backend revalidation through the outbox | done |
| 5.9 | First tenant theme: velvina-home (demo brand bravo) | done |
| 5.10 | Playwright E2E, lab CWV budget, accessibility pass | done — CI jobs gated on a runner with the API stack |

## Phase 6 — Operations & Growth Modules + Migration Tools

Plan: [docs/plans/phase-6.md](plans/phase-6.md) · Report: [docs/phase-6-checkpoint.md](phase-6-checkpoint.md) · ADR-0018

Owner decisions: payment adapters deferred; no courier account yet (framework + manual courier).

| # | Milestone | Status |
|---|---|---|
| 6.1 | Reporting (aggregates, dashboard, verifier) | done |
| 6.2 | WooCommerce importer with redirects | done — first live run in Phase 8 |
| 6.3 | CSV importers (products, stock) | done |
| 6.4 | Blog and custom content types | done |
| 6.5 | Automatic promotions | done |
| 6.6 | Abandoned-cart reminders | done |
| 6.7 | Courier framework | done (manual courier; API adapter waits for an account) |
| 6.8 | Onboarding checklist | done |
| — | JazzCash/Easypaisa adapters | deferred (owner) |

## Phase 7 — Hardening & Production Readiness

Plan: [docs/plans/phase-7.md](plans/phase-7.md) · Report: [docs/phase-7-checkpoint.md](phase-7-checkpoint.md) · ADR-0019 ·
[ASVS L2 review](security/asvs-l2.md) · [Load test](performance/load-test.md)

Owner decisions: hosting not chosen yet (server work waits); monitoring with PostHog instead of Sentry.

| # | Milestone | Status |
|---|---|---|
| 7.1 | Monitoring and alerting (PostHog, deep `/health`, request ids, failed-job alert) | done |
| 7.2 | Dependency and header audit | done |
| 7.3 | Security review against ASVS L2 | done — hosting items open (TLS, WAF, central logs) |
| 7.4 | Backup and restore drill | done locally — off-site copies / PITR with hosting |
| 7.5 | Load test and optimization | done locally (3 fixes: bulk search indexing, cached tenant resolution, pooler wiring) — capacity run on staging |
| 7.6 | Deploy and environments (local part) | done — release/rollback scripts tested with stubs; first real deploy on staging |
| 7.7 | Runbooks and docs; DoD walk-through | done — 14/16 DoD met, 2 partly (online gateway deferred; real deploy needs hosting) |

## Single-store conversion (remove multi-tenancy)

Plan: [docs/plans/remove-multi-tenancy.md](plans/remove-multi-tenancy.md). Owner decisions D1–D7 approved
2026-10-03 as recommended. Branch `single-store` in both repositories.

| # | Milestone | Status | Notes |
|---|---|---|---|
| R0 | Baseline and safety net | done | Phase 7 work committed to `main` first. Baseline: Pest 394 passed, Larastan 8 and Pint clean; web lint, typecheck, Vitest 11, `next build`, Playwright 22 passed. All 8 local databases dumped to `%USERPROFILE%\devtools\backups\pre-single-store-20261003` |
| R1 | Store identity and feature flags in settings | done | Flags = `features.*` settings (Settings `FeatureFlags` contract); `store.url` drives reset links, cart reminders and revalidation; logo upload via `SafeImage`; admin **Settings → Store** page; config gains `store.url`, `store.logo_url` (additive). Pest 407 |
| R2 | Shared services out of Platform | done | Launch checklist → Settings (`OnboardingChecks::items()`, admin **Settings → Launch checklist**); `/health` → Support, any host. `secrets:reencrypt` moves in R3 instead: while tenants exist it must loop over them, which Support cannot do |
| R3 | Switch to one database | done | One database (`pgsql` app role + `pgsql_migrator`), `bootstrap-database.sql`; module migrations loaded from `modules/*/database/migrations` (same filenames); `app:migrate` (one transaction + ledger lockdown) and `SeedDefaults`; `secrets:reencrypt` in Support. **Moved in from R4** because Larastan analyses every module: Platform module, platform panel, `platform` guard and the tenant queue classes deleted; Horizon and API docs behind `auth:staff` + new `system.monitor` (Owner only). **Moved in from R6** because the old commands no longer exist: `deploy/release.sh` (and its test) and CI use `app:migrate` and the new bootstrap SQL; Docker Compose uses the `store` database. Test harness `TestStore` replaces `TestPlatform`; isolation suite and platform tests removed, their store-relevant checks converted (search, privileges, transactional migrations, secrets rotation, request id, staff admin) |
| R4 | Delete tenancy, add guard test | done | `stancl/tenancy`, `config/tenancy.php` and `PermissionCacheBootstrapper` removed; encrypted columns have no scope; `StaffDirectory` keeps only `ensureOwner()`; `users.landlord_owner_identity_ulid` dropped (forward migration). **Guard test** `tests/Architecture/NoTenancyTest.php` fails on tenancy packages, `tenancy()`/`tenant()`, tenant context classes, `Modules\Platform` or a landlord connection (proven by planting a violation). Pest 343 |
| R5 | Per-installation naming | done | Disks `media` / `private` under `OBJECT_STORAGE_PREFIX` (data migration rewrites `media.disk`); `config/platform.php` → `config/store.php`; `TenantSettings` → `StoreSettings`; tenant/brand wording removed from code comments; `.env.example` rewritten for per-installation config (`ADMIN_DOMAIN`, `REDIS_PREFIX`, `SCOUT_PREFIX`, `OBJECT_STORAGE_PREFIX`, `BACKUP_*`). Pest 344 |
| R6 | Install, backup and deploy tooling | not started | Remaining: `store:install`, `store:seed-demo`, `db:backup [--drill]` |
| R7 | Storefront | not started | |
| R8 | Documentation | not started | |
| R9 | Root CLAUDE.md | not started | |

## Phase 8

| Phase | Scope | Status |
|---|---|---|
| 8 | Launch & Onboarding | not started — needs the hosting decision and Phase 7 sign-off |

## Decision log pointers

- ADRs 0001–0019: [docs/adr/](adr/README.md)
- Open business decisions (spec §16): tracked in the checkpoint reports
