# Progress

Updated continuously (spec §0.2 rule 6). A milestone is only `done` when the app boots,
migrations run, and the whole test suite passes.

**Current phase:** Phase 1 — Platform Foundation, **built; awaiting the Phase 1 checkpoint**
([docs/phase-1-checkpoint.md](phase-1-checkpoint.md)).
**Test suite:** 155 passing on real PostgreSQL 17 and Redis (architecture, unit, feature,
isolation). Larastan level 8 clean, Pint clean.

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

## Phases 2–8

| Phase | Scope | Status |
|---|---|---|
| 2 | Catalog, Media, Inventory, Search | not started — needs Typesense and MinIO (or Docker) on the dev machine |
| 3 | Commerce Core | not started |
| 4 | API Stabilization — v1 contract freeze | not started (Scramble already exports OpenAPI) |
| 5 | Next.js Storefront | not started |
| 6 | Operations & Growth | not started |
| 7 | Hardening | not started |
| 8 | Launch & Onboarding | not started |

## Decision log pointers

- ADRs 0001–0013: [docs/adr/](adr/README.md)
- Open business decisions (spec §16): tracked in the checkpoint reports
