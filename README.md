# platform-api

Laravel modular monolith behind one e-commerce store: Storefront API v1, Filament admin panel,
queue workers, scheduler and install/backup tooling.

**One installation = one store = one PostgreSQL database and one `.env`** (ADR-0020). The same
codebase is installed separately for each client; store name, domain, logo, currency, timezone,
theme and payment/courier credentials come from admin settings (secrets from `.env`), never from
code. Multi-tenancy was removed on purpose and must not come back —
`tests/Architecture/NoTenancyTest.php` enforces it.

> **Status:** Phases 1–7 built; Storefront API v1 frozen; single-store conversion done.
> Phase 7 waits for owner sign-off and the hosting decision. See [docs/PROGRESS.md](docs/PROGRESS.md).

## Documentation

| Document | Purpose |
|---|---|
| [docs/PROGRESS.md](docs/PROGRESS.md) | Phase and milestone status |
| [docs/architecture/overview.md](docs/architecture/overview.md) | Components, request lifecycle, layering |
| [docs/architecture/installation.md](docs/architecture/installation.md) | What one installation is; settings vs `.env`; per-installation prefixes |
| [docs/architecture/modules.md](docs/architecture/modules.md) | Module map and the internal layout every module follows |
| [docs/architecture/data-model.md](docs/architecture/data-model.md) | ERD, schema conventions, invariants |
| [docs/architecture/database-roles.md](docs/architecture/database-roles.md) | PostgreSQL roles, privileges, append-only ledger grants |
| [docs/architecture/api-conventions.md](docs/architecture/api-conventions.md) | REST conventions |
| [docs/api/openapi-v1.json](docs/api/openapi-v1.json) | **The Storefront API v1 contract** (`php artisan api:contract --check`) |
| [docs/adr/](docs/adr/) | Architecture Decision Records (ADR-0020 is the current baseline) |
| [docs/runbooks/](docs/runbooks/) | Install, deploy, rollback, backup/restore, secrets, incidents |
| [docs/go-live-checklist.md](docs/go-live-checklist.md) | What must be true before a store takes real orders |

The project brief is `../Ecommerce_Project_Blueprint.md`. Phase plans and checkpoint reports in
`docs/` are history: their tenant/landlord parts no longer apply.

## Stack

PHP 8.4 · Laravel 13 · PostgreSQL 17 (+ PgBouncer in production) · Redis · Horizon ·
Scout + Typesense 30 · Filament 5 · Sanctum · spatie/laravel-permission, -activitylog, -data,
-medialibrary, -model-states · Scramble (OpenAPI) · PostHog (errors) · Pest 4 / Larastan / Pint.

## Local setup

The canonical environment is `docker-compose.yml`. On the current Windows machine the
services run natively from a portable toolchain instead (ADR-0009):

```powershell
# every new shell: toolchain on PATH (PHP 8.4, composer, psql, redis, git, node)
. $env:USERPROFILE\devtools\env.ps1

# after a reboot: PostgreSQL 17, Redis, Typesense (in WSL2), the dev server on :8000 and a
# queue worker (search indexing and image conversions are queued)
powershell -File $env:USERPROFILE\devtools\start-services.ps1
```

First-time setup on any machine (details: [docs/runbooks/install-store.md](docs/runbooks/install-store.md)):

```bash
composer install
cp .env.example .env && php artisan key:generate
# roles + the store database (run as a PostgreSQL superuser; passwords go into .env)
psql -U postgres -h 127.0.0.1 -f database/setup/bootstrap-database.sql -v db=store \
     -v app_password="'...'" -v migrator_password="'...'" -v backup_password="'...'"
php artisan store:install --name="My Store" --url=http://localhost:3000 \
     --owner-email=you@example.com --owner-name="Your Name"
php artisan store:seed-demo           # optional: Velvina Home demo catalog (not in production)
php artisan filament:assets
```

| Surface | URL (with `ADMIN_DOMAIN` empty) |
|---|---|
| Admin panel | http://localhost:8000 |
| Storefront API | http://localhost:8000/api/v1/storefront/config |
| Horizon / API docs (Owner, `system.monitor`) | http://localhost:8000/horizon · /docs/api |
| Health | http://localhost:8000/health |

In production set `ADMIN_DOMAIN=admin.<domain>` and serve the API on `api.<domain>`.

## Commands

| Command | Purpose |
|---|---|
| `app:migrate [--no-seed]` | All migrations in one transaction as the migration role, ledger lockdown, default seeders |
| `store:install` | Idempotent first-time setup: migrate, store identity settings, owner account, search collections, launch checklist |
| `store:seed-demo [--restock]` | Demo store (Velvina Home theme), sample catalog, stock and synonyms — local only |
| `search:reindex` | Rebuild the Typesense collections from PostgreSQL |
| `db:backup [--drill]` | `pg_dump` + checksum manifest; `--drill` restores into `<db>_restore_drill` and compares |
| `secrets:reencrypt` | Re-encrypt every stored secret after an `APP_KEY` rotation |
| `api:contract --check` / `--write` | Compare / update the committed OpenAPI contract |

## Quality gates

```bash
vendor/bin/pint --test               # formatting
vendor/bin/phpstan analyse           # Larastan level 8
vendor/bin/pest --group=architecture # module boundaries, layering, no tenancy
vendor/bin/pest                      # full suite (real PostgreSQL, Redis, Typesense)
php artisan api:contract --check     # API v1 unchanged or additive
composer audit
```

Tests use the `store_test` database (and `store_test_restore_drill` for the drill tests).

## Working rules

- Business logic lives in **Actions**. Controllers, Filament pages, commands and jobs are thin callers.
- A module never touches another module's tables or models — only its contracts and events.
- Nothing store-specific in code: settings for business values, `.env` for secrets and infrastructure.
- Every feature ships with tests.
- **Never commit a secret.** `.env.example` carries placeholders only.
