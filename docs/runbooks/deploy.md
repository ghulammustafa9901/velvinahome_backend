# Runbook: deploy a release

Zero-downtime release of `platform-api` with migrations as a release gate (spec §9,
§13 Phase 7). Hosting is not chosen yet (Phase 7 plan): this runbook and `deploy/release.sh` are
hosting-neutral — any Linux server with PHP-FPM, a process supervisor and cron. Rollback:
[`rollback.md`](rollback.md).

## How a release works

```
build new release ─► cache config/routes/views ─► app:migrate (one transaction) ──────┐
   (old release keeps serving the whole time)                     a migration fails ─► release blocked, removed
                                                                                        │
switch `current` atomically ─► reload PHP-FPM, restart Horizon ─► /health shows new release? ─► done
                                                                        no ──► switch back automatically
```

`deploy/release.sh` does all of it; `deploy/tests/release.test.sh` proves the gate, the
automatic rollback and pruning in CI.

## Migration rule: expand, then contract

Migrations run **while the previous release is still serving**, and a rollback switches code
only. So every migration in a release must work with the code of the release before it:

| Allowed in one release | Split over two releases |
|---|---|
| New table, new nullable column or column with a default, new index (`CREATE INDEX CONCURRENTLY` in its own non-transactional migration, spec §4.7) | Rename a column/table: add new + write both → next release: read new, drop old |
| Backfill in a queued job | Drop a column/table: stop using it → next release: drop |
| Widen a type | Make a column `NOT NULL`: backfill + default → next release: constraint |

Reviewers check this on every migration. API v1 is frozen to additive changes (ADR-0016), which
keeps the storefront compatible with either API release.

## First-time server setup (once per environment)

1. **Packages:** PHP 8.4 FPM + CLI with `pdo_pgsql pgsql intl bcmath gd exif opcache`,
   Composer 2, PostgreSQL 17 client tools (`pg_dump`/`pg_restore` for backups), git, nginx,
   supervisor (or systemd), cron.
2. **Database:** managed PostgreSQL 17, TLS required (`DB_*_SSLMODE=require`); run
   `database/setup/bootstrap-database.sql` once as the superuser, plus once for the restore-drill
   scratch database (roles: `database-roles.md`; installation: `install-store.md`).
   **PgBouncer ≥ 1.21** in transaction mode with `max_prepared_statements = 200` in front of it,
   and `DB_POOLER_HOST`/`DB_POOLER_PORT` set — without it every request opens a new PostgreSQL
   backend (docs/performance/load-test.md). Migrations bypass the pooler on their own.
3. **Redis** (cache, sessions, queues, locks) and **Typesense 30** reachable from the app only.
4. **Layout:** `DEPLOY_BASE=/srv/platform-api` owned by the deploy user;
   `shared/.env` from `.env.example` with production values (`APP_ENV=production`,
   `APP_DEBUG=false`, a fresh `APP_KEY`, every secret from the secret store — never in git).
5. **nginx:** `root /srv/platform-api/current/public;` and pass the **resolved** path to FPM so a
   symlink switch takes effect at once:
   `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;`
   `fastcgi_param DOCUMENT_ROOT $realpath_root;`
6. **PHP-FPM:** `opcache.validate_timestamps=0` (the release script reloads FPM),
   `opcache.memory_consumption=256`, `opcache.max_accelerated_files=50000`.
7. **Horizon** under supervisor: `command=php /srv/platform-api/current/artisan horizon`,
   `autorestart=true`, `stopwaitsecs=3600` (an import may run that long).
8. **Scheduler:** cron `* * * * * cd /srv/platform-api/current && php artisan schedule:run`.
9. **Monitoring:** uptime monitor on `https://api.<domain>/health` with `X-Health-Token`;
   PostHog project key in `.env` (ADR-0019).
10. **Deploy user** may run exactly the FPM reload with sudo (`RELOAD_FPM`, default
    `sudo systemctl reload php8.4-fpm`) and nothing else.

## Each release

1. CI green on the commit (lint, Larastan, tests incl. architecture, contract,
   dependency audit, deploy scripts).
2. Take a backup point: `php artisan db:backup` (current release) — a nightly one exists, but
   a fresh one bounds what a bad migration could cost.
3. Run, as the deploy user:

   ```bash
   export DEPLOY_BASE=/srv/platform-api REPO_URL=git@github.com:<org>/platform-api.git
   export HEALTH_URL=https://api.<domain>/health HEALTH_TOKEN=<from secret store>
   /srv/platform-api/current/deploy/release.sh <tag or commit>
   ```

   (The very first release has no `current` yet: run `deploy/release.sh` from any checkout.)

4. **Blocked by `app:migrate`?** The output names the error; the whole run rolled back in one
   transaction, the old release is still live and nothing changed. Fix the migration, release
   again.
5. **Rolled back by the health check?** The release stays in `releases/` for inspection.
   Look at PostHog errors tagged with the new release id and `storage/logs`.
6. Watch PostHog errors and `/health` for 15 minutes.

## Storefront (`platform-web`)

Deploy **after** the API (the API only ever changes additively). Same pattern: build
(`pnpm install --frozen-lockfile && pnpm build`) into a new release directory, link the shared
env file, switch a symlink, restart the Node process (`next start`) under the supervisor with a
graceful reload. Required env: `API_URL` (the API over the private network), `STOREFRONT_URL`,
`STOREFRONT_SESSION_SECRET`, `SERVICE_AUTH_SECRET` and `STOREFRONT_REVALIDATE_SECRET` (same values
as the API), `TRUSTED_PROXY_HOPS` / `CLIENT_IP_HEADER` matching the edge (ASVS V11). The build
prerenders pages against `API_URL`, so the API must be reachable from the build.
