# Progress

Updated continuously (spec §0.2 rule 6). One row per milestone. A milestone is only `done`
when the app boots, migrations run, and the whole test suite passes.

**Current phase:** Phase 0 — Planning & Architecture
**Awaiting:** owner approval of the Phase 0 checkpoint
([docs/phase-0-checkpoint.md](phase-0-checkpoint.md))

---

## Phase 0 — Planning & Architecture

| # | Deliverable | Status |
|---|---|---|
| 0.1 | Repositories created (`platform-api`, `platform-web`) | done |
| 0.2 | Docker Compose local environment (PostgreSQL 17, PgBouncer, Redis, Typesense, Mailpit, MinIO) | done |
| 0.3 | CI skeleton (GitHub Actions, both repos) | done |
| 0.4 | `docs/architecture/` overview | done |
| 0.5 | ERD — landlord (incl. companies and brands) and tenant core tables | done |
| 0.6 | Module map + module internal structure | done |
| 0.7 | API conventions document | done |
| 0.8 | PostgreSQL role and privilege design | done |
| 0.9 | Initial ADRs (0001–0008) | done |
| 0.10 | Phase 1 milestone plan with tests | done |
| 0.11 | Understanding summary, concerns and contradictions | done |
| — | **Owner approval of architecture docs and ERD** | **blocked — awaiting owner** |

### Open blockers for Phase 1

| Blocker | Detail |
|---|---|
| Development environment | The current machine has PHP 8.3 (spec requires 8.4+), no Docker, no PostgreSQL, no pnpm, and runs XAMPP (Apache + MySQL, both excluded by the spec). Phase 1 Milestone 1.1 cannot be verified until this is resolved. See the checkpoint report. |
| Platform name and domain | Spec §16.1 — needed for the landlord panel host and the `{{PLATFORM_NAME}}` placeholder |
| VOG / SIX | Spec §16.5 — one brand or two tenants under one company |
| Initial topology per tenant | Spec §16.6 — shared or dedicated for the first brands |

---

## Phase 1 — Platform Foundation

Plan: [docs/plans/phase-1.md](plans/phase-1.md). Not started; blocked on the Phase 0 checkpoint.

| # | Milestone | Status |
|---|---|---|
| 1.1 | Laravel app, module skeleton, quality gates | not started |
| 1.2 | Landlord database and tenancy core | not started |
| 1.3 | Redis, Horizon, scheduler, outbox | not started |
| 1.4 | Tenant lifecycle commands | not started |
| 1.5 | Identity: staff, customers, platform users | not started |
| 1.6 | Filament shells: tenant admin and platform panel | not started |
| 1.7 | Settings and feature flags | not started |
| 1.8 | Tenant isolation test suite | not started |

---

## Phases 2–8

Not started. Scope per spec §13; each phase gets its own plan in `docs/plans/phase-N.md`
written at the start of that phase and approved at the preceding checkpoint.

| Phase | Scope | Status |
|---|---|---|
| 2 | Catalog, Media, Inventory, Search | not started |
| 3 | Commerce Core (cart, checkout, orders, payments, shipping) | not started |
| 4 | API Stabilization — v1 contract freeze | not started |
| 5 | Next.js Storefront | not started |
| 6 | Operations & Growth Modules + migration tools | not started |
| 7 | Hardening & Production Readiness | not started |
| 8 | Launch & Tenant Onboarding | not started |

---

## Decision log pointers

- ADRs: [docs/adr/](adr/) — 0001–0008 accepted, deferred list in
  [docs/adr/README.md](adr/README.md)
- Open business decisions: spec §16, tracked in the checkpoint report
