# Progress

Updated continuously (spec §0.2 rule 6). A milestone is only `done` when the app boots,
migrations run, and the whole test suite passes.

**Current phase:** Phase 4 built — **API v1 frozen** ([docs/phase-4-checkpoint.md](phase-4-checkpoint.md)).
Next: Phase 5, the Next.js storefront. The owner asked for Phase 3 then Phase 4.
**Test suite:** 296 passing (1 skipped) on real PostgreSQL 17, Redis and Typesense 30
(architecture, unit, feature, isolation, multi-process concurrency). Larastan level 8 clean,
Pint clean.

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

## Phases 5–8

| Phase | Scope | Status |
|---|---|---|
| 3 | Commerce Core | **built** — see above |
| 4 | API Stabilization — v1 contract freeze | **built, v1 frozen** — see above |
| 5 | Next.js Storefront | not started |
| 6 | Operations & Growth | not started |
| 7 | Hardening | not started |
| 8 | Launch & Onboarding | not started |

## Decision log pointers

- ADRs 0001–0016: [docs/adr/](adr/README.md)
- Open business decisions (spec §16): tracked in the checkpoint reports
