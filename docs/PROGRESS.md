# Progress

Updated continuously (spec §0.2 rule 6). A milestone is only `done` when the app boots,
migrations run, and the whole test suite passes.

**Current phase:** Phase 6 built — **Operations & Growth + migration tools** ([docs/phase-6-checkpoint.md](phase-6-checkpoint.md)).
Next: Phase 7, Hardening & Production Readiness.
**Test suite:** 360 passing (1 skipped) on real PostgreSQL 17, Redis and Typesense 30
(architecture, unit, feature, isolation, multi-process concurrency). Larastan level 8 clean,
Pint clean. platform-web: ESLint, strict typecheck, Vitest, `next build`, 22 Playwright E2E
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

## Phases 7–8

| Phase | Scope | Status |
|---|---|---|
| 3 | Commerce Core | **built** — see above |
| 4 | API Stabilization — v1 contract freeze | **built, v1 frozen** — see above |
| 5 | Next.js Storefront | **built** — see above |
| 6 | Operations & Growth | **built** — see above |
| 7 | Hardening | not started |
| 8 | Launch & Onboarding | not started |

## Decision log pointers

- ADRs 0001–0018: [docs/adr/](adr/README.md)
- Open business decisions (spec §16): tracked in the checkpoint reports
