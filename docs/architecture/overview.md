# Architecture Overview

> Source of truth for requirements: `Commerce_Platform_Master_Spec.md` (v2.0, project root).
> This document explains *how* the spec is realised; it never overrides it. Labels
> **FINAL / DEFAULT / YOUR CALL** carry the meaning defined in §0.1 of the spec.

## 1. What this system is

A production-grade e-commerce application for **one store per installation**. The Platform
Owner's team builds and operates the store for a client; client staff run day-to-day operations
through the admin. Another client gets a **separate installation** of the same code, with its
own database, `.env` and deployment (ADR-0020). There is no multi-tenancy and no public
self-signup.

## 2. Component map

```text
                     ┌───────────────────────────────────────────────┐
  Shoppers ─────────▶│  platform-web — Next.js storefront            │
  (store domain)     │  commerce-core + theme + BFF routes           │
                     └──────────────────┬────────────────────────────┘
                                        │ REST /api/v1  (server-side, signed)
                     ┌──────────────────▼────────────────────────────┐
  Store staff ──────▶│  platform-api — Laravel modular monolith      │
  admin.<domain>     │  Storefront API · Filament admin ·            │
                     │  queue workers · scheduler                    │
                     └──┬──────────────┬──────────┬──────────────────┘
                        │              │          │
                  PostgreSQL       Redis      Typesense     Object storage
                  (one database,  (cache,    ({prefix}      (media / private
                   via PgBouncer)  queues,    products)      under a prefix)
                                   rate limits)
                        │
             Adapters ──┴──▶ payment gateways · couriers · email · SMS/WhatsApp
```

## 3. Repositories (FINAL — spec §3.2)

| Repository | Contents |
|---|---|
| `platform-api` | Laravel app: domain modules, Storefront API, Filament admin, jobs, installation tooling, all documentation. |
| `platform-web` | pnpm + Turborepo monorepo: `apps/storefront`, `apps/mobile` (later), `packages/*`, `themes/*`. |

Code lives only in private GitHub repositories under the Platform Owner's organisation.
Servers receive code exclusively through the deployment pipeline. **Code is never edited
on a server.**

## 4. Request lifecycle

1. Cloudflare terminates TLS, applies WAF/bot rules, serves cached static assets.
2. **Storefront request** → Next.js loads the cached store config (`GET /api/v1/storefront/config`:
   identity, theme, tokens, flags) and renders Server Components. Data comes from
   `platform-api` via the generated TypeScript client; authenticated calls go through BFF
   server actions so no token ever reaches browser JavaScript.
3. **API request** → the controller validates input into a DTO and calls an **Action**. All
   business logic lives in Actions.
4. Side effects (email, search indexing, storefront revalidation) are dispatched as queued jobs
   or outbox messages **after the database transaction commits**.

## 5. Layering (FINAL — spec §3.4)

```text
HTTP controller │ Filament resource │ Artisan command │ queued job │ webhook handler
        └──────────────── all thin callers ────────────────┘
                              ▼
                    Action  (the only place business logic lives)
                      ├─ input: validated DTO (spatie/laravel-data)
                      ├─ authorisation: permission check inside the Action
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
- **No multi-tenancy** (`tests/Architecture/NoTenancyTest.php`, ADR-0020).

## 6. Per-installation configuration

Store identity is data (admin settings), infrastructure is `.env`. See
[installation.md](installation.md).

## 7. Related documents

| Document | Covers |
|---|---|
| [installation.md](installation.md) | One installation per store: settings vs `.env`, shared infrastructure, install procedure |
| [modules.md](modules.md) | Module map and the internal structure every module follows |
| [data-model.md](data-model.md) | ERD, key invariants |
| [database-roles.md](database-roles.md) | PostgreSQL roles and privileges, append-only ledger grants |
| [api-conventions.md](api-conventions.md) | REST conventions, errors, pagination, idempotency, rate limits |
| [../adr/](../adr/) | Architecture Decision Records (ADR-0020: single-store application) |
| [../runbooks/](../runbooks/) | Install, deploy, roll back, back up and restore, rotate secrets, incidents |
| [../PROGRESS.md](../PROGRESS.md) | Live status |
