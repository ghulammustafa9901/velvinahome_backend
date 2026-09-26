# Architecture Overview

> Source of truth for requirements: `Commerce_Platform_Master_Spec.md` (repo root of the
> project workspace). This document explains *how* the spec is realised; it never
> overrides it. Labels **FINAL / DEFAULT / YOUR CALL** carry the meaning defined in §0.1
> of the spec.

## 1. What this system is

A managed-service, multi-brand e-commerce platform. The Platform Owner's team builds and
operates storefronts for client companies; client staff run day-to-day operations through
a per-brand admin panel. There is no public self-signup.

**One tenant = one brand = one website = one PostgreSQL database.** A company may own
several brands; each brand is still a fully separate tenant with its own database, admin
and domain.

## 2. Component map

```text
                     ┌───────────────────────────────────────────────┐
  Shoppers ─────────▶│  platform-web — Next.js storefront            │
  (per brand domain) │  commerce-core + tenant themes + BFF routes   │
                     └──────────────────┬────────────────────────────┘
                                        │ REST /api/v1  (server-side, signed)
                     ┌──────────────────▼────────────────────────────┐
  Client staff ─────▶│  platform-api — Laravel modular monolith      │◀── Platform team
  admin.<brand>      │  Storefront API · tenant Filament panel ·     │    platform panel
                     │  landlord Filament panel · queue workers      │
                     └──┬─────────┬──────────┬─────────┬─────────────┘
                        │         │          │         │
                 Landlord DB  Tenant DBs  Redis    Typesense   Object storage
                 (registry)   (1 per      (cache,  (per-tenant (per-tenant
                              brand,      queues,   collections) prefix)
                              via         rate
                              PgBouncer)  limits)
                        │
             Adapters ──┴──▶ payment gateways · couriers · email · SMS/WhatsApp
```

## 3. Repositories (FINAL — spec §3.2)

| Repository | Contents |
|---|---|
| `platform-api` | Laravel app: domain modules, Storefront API, both Filament panels, jobs, tenant lifecycle commands, all platform documentation. |
| `platform-web` | pnpm + Turborepo monorepo: `apps/storefront`, `apps/mobile` (later), `packages/*`, `themes/*`. |

Code lives only in private GitHub repositories under the Platform Owner's organisation.
Servers receive code exclusively through the deployment pipeline. **Code is never edited
on a server.**

## 4. Request lifecycle (shared topology)

1. Cloudflare terminates TLS, applies WAF/bot rules, serves cached static assets.
2. **Storefront request** → Next.js middleware reads the hostname, loads cached tenant
   storefront config, renders Server Components. Data comes from `platform-api` via the
   generated TypeScript client; authenticated calls go through BFF route handlers so no
   token ever reaches browser JavaScript.
3. **API request** → Laravel resolves the tenant from the request hostname against the
   landlord `domains` table. Unknown host → `404`, never a default tenant.
4. Tenancy bootstrappers switch the database connection, cache prefix, filesystem prefix,
   queue context, Typesense collection prefix and log context to that tenant.
5. The controller validates input into a DTO and calls an **Action**. All business logic
   lives in Actions.
6. Side effects (email, search indexing, webhooks) are dispatched as queued jobs
   **after the database transaction commits**, each carrying tenant context.

## 5. Layering (FINAL — spec §3.4)

```text
HTTP controller │ Filament resource │ Artisan command │ queued job │ webhook handler
        └──────────────── all thin callers ────────────────┘
                              ▼
                    Action  (the only place business logic lives)
                      ├─ input: validated DTO (spatie/laravel-data)
                      ├─ authorisation: Policy check inside the Action
                      ├─ persistence: Eloquent models, wrapped in transactions
                      └─ output: domain events → queued listeners (after commit)
```

Rules that CI enforces with Pest architecture tests:

- A module never queries another module's tables or models directly; it goes through the
  other module's published **contract** (interface) or reacts to its **domain events**.
- Controllers, Filament resources, commands and jobs contain no business rules.
- `$request->all()` never reaches a model. Input enters Actions as DTOs.
- The storefront computes no business values: prices, discounts, shipping, tax, totals and
  stock availability are always computed by `platform-api`.

## 6. Deployment topologies (FINAL — spec §4.3)

The same code supports both, selected by configuration only:

- **Shared** — one application instance serves many tenants, switching database per request.
- **Dedicated** — one installation pinned to a single tenant via environment config.

Moving a tenant between topologies must require **zero code changes**.

## 7. Related documents

| Document | Covers |
|---|---|
| [tenancy.md](tenancy.md) | Tenant resolution, isolation of every concern, lifecycle commands, migrations |
| [modules.md](modules.md) | Module map and the internal structure every module follows |
| [data-model.md](data-model.md) | Landlord and tenant ERDs, key invariants |
| [database-roles.md](database-roles.md) | PostgreSQL role and privilege design, append-only ledger grants |
| [api-conventions.md](api-conventions.md) | REST conventions, errors, pagination, idempotency, rate limits |
| [../adr/](../adr/) | Architecture Decision Records |
| [../plans/phase-1.md](../plans/phase-1.md) | Phase 1 milestone plan with tests |
| [../PROGRESS.md](../PROGRESS.md) | Live phase and milestone status |
