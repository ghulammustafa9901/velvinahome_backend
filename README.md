# platform-api

Laravel modular monolith behind a multi-brand commerce platform: Storefront API, tenant admin
panel, platform (landlord) control panel, queue workers and tenant lifecycle tooling.

**One tenant = one brand = one website = one PostgreSQL database.**

> **Current status: Phase 1 (Platform Foundation) built — awaiting the Phase 1 checkpoint.**
> Tenancy, provisioning, identity, both admin shells, settings/flags, outbox and the tenant
> isolation suite are in place. No commerce module exists yet (Phase 2 onward).
> See [docs/phase-1-checkpoint.md](docs/phase-1-checkpoint.md) and [docs/PROGRESS.md](docs/PROGRESS.md).

## Documentation

| Document | Purpose |
|---|---|
| [docs/phase-1-checkpoint.md](docs/phase-1-checkpoint.md) | What Phase 1 delivered, deviations, open questions |
| [docs/architecture/overview.md](docs/architecture/overview.md) | Components, request lifecycle, layering |
| [docs/architecture/tenancy.md](docs/architecture/tenancy.md) | Tenant resolution, isolation of every concern, lifecycle, migrations |
| [docs/architecture/modules.md](docs/architecture/modules.md) | Module map and the internal layout every module follows |
| [docs/architecture/data-model.md](docs/architecture/data-model.md) | Landlord and tenant ERDs, schema conventions, invariants |
| [docs/architecture/database-roles.md](docs/architecture/database-roles.md) | PostgreSQL roles, privileges, append-only ledger grants |
| [docs/architecture/api-conventions.md](docs/architecture/api-conventions.md) | REST conventions |
| [docs/adr/](docs/adr/) | Architecture Decision Records |
| [docs/runbooks/](docs/runbooks/) | Operational procedures |

The requirements live in `Commerce_Platform_Master_Spec.md`. That document is the contract.

## Stack

PHP 8.4 · Laravel 13 · PostgreSQL 17 (one database per tenant) · Redis · Horizon ·
Filament 5 (two panels) · Sanctum · spatie/laravel-permission, -activitylog, -data ·
stancl/tenancy 3 (multi-database) · Scramble (OpenAPI) · Pest 4 / Larastan / Pint.

## Local setup

The canonical environment is `docker-compose.yml`. On the current Windows machine the
services run natively from a portable toolchain instead (ADR-0009):

```powershell
# every new shell: toolchain on PATH (PHP 8.4, composer, psql, redis, git, node)
. $env:USERPROFILE\devtools\env.ps1

# after a reboot: start PostgreSQL 17 and Redis
powershell -File $env:USERPROFILE\devtools\start-services.ps1
```

First-time setup on any machine:

```bash
composer install
cp .env.example .env && php artisan key:generate
# roles + landlord database (run as a PostgreSQL superuser; passwords go into .env)
psql -U postgres -h 127.0.0.1 -f database/setup/bootstrap-landlord.sql \
     -v app_password="'...'" -v migrator_password="'...'" \
     -v provisioner_password="'...'" -v backup_password="'...'"
php artisan landlord:migrate          # landlord schema, as the migration role
php artisan platform:seed-demo        # 2 companies, 3 brands, all on *.localhost
php artisan platform:user you@example.com "Your Name" --role=owner
php artisan filament:assets
```

Serve locally (browsers resolve `*.localhost` to 127.0.0.1 without a hosts file):

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

| Surface | URL |
|---|---|
| Platform (landlord) panel | http://platform.localhost:8000 |
| Tenant admin, demo brands | http://admin.alpha.localhost:8000 · admin.bravo · admin.charlie |
| Storefront API | http://api.alpha.localhost:8000/api/v1/storefront/config |
| Horizon / API docs | http://platform.localhost:8000/horizon · /docs/api |

`bravo` and `charlie` belong to the same company: sign in to one as its owner and the brand
switcher in the top bar moves you to the other.

## Commands

| Command | Purpose |
|---|---|
| `landlord:migrate` | Landlord migrations as the migration role, plus audit-table lockdown |
| `company:create {slug} {name}` | Company (landlord record only, no database) |
| `tenant:create {company} {slug} {name} --storefront=` | Provision a brand end to end; idempotent, re-run to resume after a failure |
| `tenant:suspend` / `tenant:activate {slug}` | Change a brand's status (suspended → 503) |
| `tenant:export {slug}` | `pg_dump` + media manifest |
| `tenant:delete {slug} --confirm={slug}` | Requires a successful backup from the last hour |
| `tenants:migrate [--tenant=]` | Every tenant, one transaction each; non-zero exit on any failure |
| `platform:user {email} {name} --role=` | Platform panel user (password shown once) |
| `platform:seed-demo` | Local demo companies and brands |

## Quality gates

```bash
vendor/bin/pint --test               # formatting
vendor/bin/phpstan analyse           # Larastan level 8
vendor/bin/pest --group=architecture # module boundaries and layering
vendor/bin/pest --group=isolation    # tenant isolation — a release blocker
vendor/bin/pest                      # full suite (real PostgreSQL + Redis)
composer audit
```

Tests use a separate landlord database (`platform_landlord_test`) and `ptest_`-prefixed tenant
databases; the three fixture brands are provisioned once and reused across runs.

## Working rules

- Business logic lives in **Actions**. Controllers, Filament pages, commands and jobs are thin callers.
- A module never touches another module's tables or models — only its contracts and events.
- Every feature ships with tests and is tenant-aware from its first line.
- **Never commit a secret.** `.env.example` carries placeholders only.
