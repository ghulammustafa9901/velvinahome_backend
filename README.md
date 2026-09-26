# platform-api

Laravel modular monolith behind a multi-brand commerce platform: Storefront API, tenant admin
panel, platform (landlord) control panel, queue workers and tenant lifecycle tooling.

**One tenant = one brand = one website = one PostgreSQL database.**

> **Current status: Phase 0 (Planning & Architecture).** The application is not scaffolded
> yet. This repository currently holds the architecture documentation, ADRs, the local
> environment definition and the CI skeleton. Application code begins after the Phase 0
> checkpoint is approved — see [docs/phase-0-checkpoint.md](docs/phase-0-checkpoint.md).

## Documentation

| Document | Purpose |
|---|---|
| [docs/architecture/overview.md](docs/architecture/overview.md) | Start here: components, request lifecycle, layering |
| [docs/architecture/tenancy.md](docs/architecture/tenancy.md) | Tenant resolution, isolation of every concern, lifecycle, migrations |
| [docs/architecture/modules.md](docs/architecture/modules.md) | Module map and the internal layout every module follows |
| [docs/architecture/data-model.md](docs/architecture/data-model.md) | Landlord and tenant ERDs, schema conventions, invariants |
| [docs/architecture/database-roles.md](docs/architecture/database-roles.md) | PostgreSQL roles, privileges, append-only ledger grants |
| [docs/architecture/api-conventions.md](docs/architecture/api-conventions.md) | REST conventions: resources, errors, pagination, idempotency, rate limits |
| [docs/adr/](docs/adr/) | Architecture Decision Records |
| [docs/plans/phase-1.md](docs/plans/phase-1.md) | Phase 1 milestone plan with tests per milestone |
| [docs/PROGRESS.md](docs/PROGRESS.md) | Live phase and milestone status |
| [docs/runbooks/](docs/runbooks/) | Operational procedures |

The requirements themselves live in `Commerce_Platform_Master_Spec.md`. That document is the
contract; everything here explains how it is implemented and never overrides it.

## Stack

PHP 8.4+ · Laravel · PostgreSQL 17+ (one database per tenant) · PgBouncer · Redis ·
Horizon · Typesense (Laravel Scout) · Filament (two panels) · Sanctum ·
spatie/laravel-permission · stancl/tenancy (multi-database) · Cloudflare R2 ·
Pest / Larastan / Pint.

## Local setup

**Prerequisites:** Docker (or Podman with compose), PHP 8.4+, Composer 2. Shared hosting and
XAMPP's bundled MySQL/Apache are not supported — the platform requires PostgreSQL, Redis and
Typesense.

```bash
# 1. Backing services: PostgreSQL 17, PgBouncer, Redis, Typesense, Mailpit, MinIO
docker compose up -d

# 2. Application (from Phase 1 onward)
composer install
cp .env.example .env
php artisan key:generate

# 3. Landlord schema
php artisan migrate --database=landlord

# 4. Demo data: 2 companies, 3 brands (one company owns two), all on *.localhost
php artisan platform:seed-demo
```

Then add to your hosts file:

```text
127.0.0.1  platform.localhost
127.0.0.1  prifit.localhost        admin.prifit.localhost        api.prifit.localhost
127.0.0.1  velvina.localhost       admin.velvina.localhost       api.velvina.localhost
127.0.0.1  vogsix.localhost        admin.vogsix.localhost        api.vogsix.localhost
```

| Service | URL |
|---|---|
| Platform (landlord) panel | http://platform.localhost |
| A tenant admin | http://admin.prifit.localhost |
| Mailpit | http://localhost:8025 |
| MinIO console | http://localhost:9001 |
| Typesense | http://localhost:8108 |
| Horizon | http://platform.localhost/horizon |

## Commands

| Command | Purpose |
|---|---|
| `php artisan company:create` | Create a company (landlord record only, no database) |
| `php artisan tenant:create` | Provision a brand end to end. Idempotent, re-runnable after a failure |
| `php artisan tenant:suspend` / `tenant:activate` | Change a brand's status |
| `php artisan tenant:export` | Full export (DB dump + media manifest) for backup or client exit |
| `php artisan tenant:delete` | Requires confirmation **and** a fresh verified backup |
| `php artisan tenants:migrate` | Migrate every tenant database — part of every deployment |
| `php artisan search:reindex --tenant=` | Rebuild a tenant's Typesense collections |
| `php artisan platform:seed-demo` | Local demo companies and brands |

## Quality gates

```bash
vendor/bin/pint                      # formatting
vendor/bin/phpstan analyse           # Larastan, level 8 on new code
vendor/bin/pest --group=architecture # module boundaries and layering
vendor/bin/pest --group=isolation    # tenant isolation — a release blocker
vendor/bin/pest --coverage           # full suite
composer audit                       # dependency advisories
```

All of these run in CI. `main` is protected on them, and **tenant isolation is its own
required check** so a failure there is never mistaken for an ordinary test break.

## Working rules

- Business logic lives in **Actions**. Controllers, Filament resources, commands, jobs and
  webhook handlers are thin callers.
- A module never touches another module's tables or models — only its contracts and events.
- Every feature ships with tests. Every feature is tenant-aware from its first line.
- **Never commit a secret.** `.env.example` carries placeholders only.
- Code is never edited on a server. Deployment is the only path to production.
