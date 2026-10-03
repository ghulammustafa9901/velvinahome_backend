# Phase 1 Checkpoint — Platform Foundation

> **Historical record. Superseded in part by [plans/remove-multi-tenancy.md](plans/remove-multi-tenancy.md) and
> [ADR-0020](adr/0020-single-store-application-without-multi-tenancy.md) (2026-10-03):** the application is now single-store — tenants,
> the landlord database, the platform panel and isolation tests described here no longer exist.


**Date:** 2026-09-26 · **Status:** built, awaiting owner approval before Phase 2 (spec §0.2 rule 5)

## 1. Exit criteria (spec §13)

| Criterion | Result |
|---|---|
| Two tenants run side by side locally with fully isolated data, cache, queues, storage | **Met.** Three demo brands (`alpha`, and `bravo` + `charlie` of one company) run locally; the isolation suite proves it for fixture brands. |
| Isolation tests pass | **Met** — `vendor/bin/pest --group=isolation`, 24 tests, plus DB privilege tests. Search channel pending Phase 2. |
| Staff can log in to their own tenant admin only | **Met** — credentials, replayed sessions and hand-off tokens are all refused on other brands. |

## 2. What was built

- **Tenancy:** stancl/tenancy (multi-database) with our own host resolver and domain kinds
  (storefront / admin / api). Unknown host → 404 everywhere, including the Livewire
  endpoint; suspended brand → 503; shared and dedicated topology by config only.
- **Provisioning without a superuser:** `platform_provisioner` creates each brand's database
  plus an app role (DML only) and a migrator role (schema owner). `CONNECT` is revoked from
  `PUBLIC`; ledgers lose `UPDATE/DELETE/TRUNCATE` for the app role after every migration.
- **`tenant:create`** runs seven recorded steps and resumes after a failure without
  duplicating anything (tested). **`tenants:migrate`** runs each tenant in one transaction:
  a failing migration leaves that database byte-for-byte unchanged and exits non-zero (tested).
- **Identity:** platform users (landlord), staff and customers (tenant), each with its own
  guard. Mandatory authenticator-app 2FA on both panels, Argon2id, breached-password check,
  lockout, and login audits for all three populations.
- **Panels:** platform panel (companies, brands, provisioning, suspend/activate, support
  access with reason) and tenant admin (staff and roles), both calling the same Actions as
  the CLI. Horizon and API docs only on the platform host.
- **Settings & flags**, **transactional outbox**, **scheduler fan-out**, request-id and
  tenant-id log context, OpenAPI export (Scramble).

## 3. Problems found and fixed by the tests

1. The injected `Cache\Repository` singleton ignored tenancy — a real cross-tenant leak (ADR-0012).
2. The scheduler fan-out dispatched jobs *after* tenancy ended (`PendingDispatch` returned out of a closure) — jobs would have run without a tenant.
3. Outbox publishing relied on queue config for after-commit behaviour; now enforced by the job itself.
4. Newly created users had no 2FA attributes, crashing the MFA check in strict mode.

## 4. Deviations from the plan and docs

| Item | Deviation | Record |
|---|---|---|
| Module wiring | Root PSR-4 instead of Composer path repositories | ADR-0011 |
| Cache isolation | Tags, not key prefixes | ADR-0012 |
| Owner identity | No credentials in the landlord; hand-off tokens | ADR-0013 |
| Local environment | Portable PHP 8.4 / PostgreSQL 17 / Redis 8 in `%USERPROFILE%\devtools` | ADR-0009 update |
| Filament Shield | Not installed; permissions enforced in Actions and resource `can*` methods | this report |
| Seeders | In `src/Seeders/` of each module | ADR-0011 |
| Public schema of `company_owner_identities` | no password / 2FA columns | ADR-0013 |

## 5. Deferred (known gaps)

- Horizon supervisor cannot run on Windows (`pcntl`); use `php artisan queue:work redis` locally. CI/production unaffected.
- Failed-job alerting, Sentry, Pulse — Phase 7 (observability milestone).
- PgBouncer not used locally (ADR-0009); staging smoke test remains a Phase 7 gate.
- `tenant:export` is proven to produce a dump; a restore-into-fresh-database test belongs with the Phase 7 restore drill.
- Seeding default tax, shipping, email templates and pages waits for those modules (Phases 2–3).
- CI workflow updated but **not yet run on GitHub** (no remote configured on this machine).

## 6. Open questions for the owner (spec §16)

1. Platform name and platform domain (currently `platform.localhost`).
2. VOG and SIX: one brand or two tenants under one company?
3. Initial topology per brand (shared vs dedicated).
4. Approve moving to Phase 2. Phase 2 needs Typesense and S3-compatible storage locally —
   **installing Docker Desktop (or WSL2) on this machine is the recommended way**, and needs a
   reboot, so it is left to you.

## 7. How to look at it

```powershell
. $env:USERPROFILE\devtools\env.ps1
powershell -File $env:USERPROFILE\devtools\start-services.ps1   # after a reboot
php artisan serve --host=127.0.0.1 --port=8000
```

Platform panel: http://platform.localhost:8000 · brand admins: http://admin.alpha.localhost:8000,
admin.bravo…, admin.charlie… · API: http://api.alpha.localhost:8000/api/v1/storefront/config
