# Phase 1 Plan — Platform Foundation

**Goal:** tenancy, authentication, queues, caching and both admin shells exist and are proven
isolated, **before any business module is written**. Nothing in Phase 1 sells a product; it
makes every later module tenant-aware by construction.

**Exit criteria (spec §13):** two tenants run side by side locally with fully isolated data,
cache, queues, storage and search; isolation tests pass; staff can log in to their own tenant
admin only.

**Rule applying to every milestone (spec §0.2):** at the end of each one the app boots,
migrations run on all tenants, and the whole test suite passes. A milestone with failing tests
is not finished.

---

## Milestone 1.1 — Laravel app, module skeleton, quality gates

**Build**

1. Laravel (latest stable) on PHP 8.4, `declare(strict_types=1)` everywhere.
2. `modules/` with Composer path repositories and PSR-4 `Modules\<Module>\`. Create the
   skeletons for `Support`, `Platform`, `Identity`, `Settings` only — the other 17 modules are
   created in the phase that needs them, so no empty directories rot.
3. Service-provider auto-registration per module (routes, migrations, policies, events,
   Filament components).
4. Pest, Larastan (level 8 on new code), Pint, `composer audit` wired into CI.
5. `Support` kernel: base Action, base DTO, `Money` value object, `HasUlid` trait, result types.

**Tests**

| Test | Asserts |
|---|---|
| `architecture/ActionsTest` | every `*/Actions/*` class is `final`, single public entry point, no HTTP/Request/Filament type in its signature |
| `architecture/BoundariesTest` | no module imports another module's `Models`, `Actions` or `Support` |
| `architecture/ThinCallersTest` | no controller, Filament class, command or job performs a write directly |
| `architecture/StrictTypesTest` | every PHP file declares `strict_types=1` |
| `architecture/NoMassAssignmentTest` | `$request->all()`/`->input()` never reaches `create`/`update`/`fill` |
| `architecture/MoneyTest` | no `float` declaration or cast in any class touching money |
| `unit/MoneyTest` | integer arithmetic, currency mismatch rejection, allocation/rounding across lines |

**Done when** CI is green on all six architecture tests with the module skeletons in place.

---

## Milestone 1.2 — Landlord database and tenancy core

**Build**

1. Landlord migrations for every table in [data-model.md](../architecture/data-model.md) §1:
   `companies`, `tenants`, `domains`, `tenant_databases`, `plans`, `tenant_feature_flags`,
   `company_owner_identities`, `company_owner_brand_links`, `platform_users`,
   `platform_activity_log`, `impersonation_sessions`, `tenant_metrics_daily`,
   `tenant_backups`, `tenant_migration_runs`, `releases`.
2. stancl/tenancy in multi-database mode; `Tenant` model with the ULID key; hostname
   resolution against `domains`, with `domains.kind` selecting the middleware stack.
3. Bootstrappers: database, cache (prefix `t:{id}:`), filesystem, queue; plus custom
   bootstrappers for Typesense collection prefixing and log/Sentry tenant context.
4. Unknown host → 404, no fallback tenant, at every entry point (storefront API, admin,
   webhooks).
5. Topology switch (`TENANCY_TOPOLOGY=shared|dedicated`, `TENANCY_PINNED_TENANT`).
6. Encrypted casts on `tenant_databases` credential columns.
7. Structured JSON logging with `request_id`, `tenant_id`, `user_id` on every line.

**Tests**

| Test | Asserts |
|---|---|
| `feature/TenantResolutionTest` | known storefront/admin/api host resolves to the right tenant; unknown host 404s; no fallback tenant under any configuration |
| `feature/DomainKindTest` | an admin route is unreachable on a storefront host and vice versa |
| `feature/TopologyTest` | the same request behaves identically in shared and dedicated configuration |
| `feature/LandlordPurityTest` | the landlord schema contains no table capable of holding customer, order, product or payment data |
| `feature/CredentialEncryptionTest` | tenant DB credentials are unreadable in the raw column and require `APP_KEY` |
| `unit/LogContextTest` | every log line carries tenant and request id |

---

## Milestone 1.3 — Redis, Horizon, scheduler, outbox

**Build**

1. Redis cache with tenant-prefixed keys and tag-based invalidation; rate-limit keys include
   the tenant.
2. Horizon with the queue set `critical`, `default`, `notifications`, `search`, `media`,
   `reports`; retries with backoff; failed-job alerting.
3. Tenant context in every job payload, re-initialised on handling. A job arriving without
   tenant context on a tenant queue **fails loudly**.
4. A landlord-level scheduler that iterates active tenants and dispatches tenant-scoped jobs,
   continuing past a failing tenant.
5. `outbox_messages` table plus the dispatcher, so a domain event with an external effect is
   written in the business transaction and published after commit.

**Tests**

| Test | Asserts |
|---|---|
| `isolation/CacheIsolationTest` | the same key in two tenants holds different values; flushing one leaves the other intact |
| `isolation/QueueIsolationTest` | a job dispatched in tenant A writes only to tenant A when processed later |
| `feature/JobWithoutTenantTest` | a tenant-queue job lacking context fails rather than running in a default tenant |
| `feature/SchedulerTest` | a tenant task runs once per active tenant, skips suspended tenants, and a failure on one does not abort the loop |
| `feature/OutboxTest` | nothing is published for a rolled-back transaction; nothing is lost on a committed one; republishing is idempotent |
| `feature/RateLimitTest` | limiter keys include the tenant, so one tenant cannot exhaust another's budget |

---

## Milestone 1.4 — Tenant lifecycle commands

**Build**

1. `CreateCompany` / `company:create`.
2. `CreateTenant` / `tenant:create` as **ordered, individually re-runnable steps** with
   `provisioning_state` recorded per step: create brand record and domains → create database
   and least-privilege roles (per
   [database-roles.md](../architecture/database-roles.md) §2) → run tenant migrations →
   apply the append-only ledger lockdown → seed roles, permissions, settings, default tax and
   shipping, email templates, default pages → create Typesense collections → set the storage
   prefix → create the owner admin → print the go-live checklist.
3. `tenant:suspend` / `tenant:activate`, `tenant:export` (pg_dump + media manifest),
   `tenant:delete` (confirmation + fresh verified backup required).
4. `tenants:migrate`: transactional per tenant, per-tenant reporting into
   `tenant_migration_runs`, non-zero exit if any tenant fails.
5. `platform:seed-demo`: two companies, three brands — one company with a single brand, one
   company with two brands (the multi-brand case), all on `*.localhost`.

**Tests**

| Test | Asserts |
|---|---|
| `feature/CreateTenantTest` | a full provision produces a working tenant: database, roles, schema, seeds, collections, storage prefix, owner account |
| `feature/CreateTenantIdempotencyTest` | interrupting after each step and re-running completes without duplicating anything |
| `feature/TenantMigrateTest` | pending migrations apply to every tenant; `tenant_migration_runs` records each |
| `feature/TenantMigrateFailureTest` | **a deliberately failing migration leaves that tenant's database unchanged and returns non-zero** (spec §15) |
| `feature/DatabasePrivilegeTest` | the app role cannot run DDL; cannot `CONNECT` to another tenant's database; has no `SUPERUSER`/`CREATEDB`/`CREATEROLE` |
| `feature/LedgerLockdownTest` | every append-only table rejects `UPDATE`/`DELETE` under the app role, and the revoke list covers every table marked append-only in code |
| `feature/TenantDeleteTest` | deletion refuses without confirmation and without a fresh backup |
| `feature/TenantExportTest` | an export restores into a fresh database with identical data |

---

## Milestone 1.5 — Identity: staff, customers, platform users

**Build**

1. Three populations, three guards, three tables, never mixed: `platform_users` (landlord),
   `users` (tenant staff), `customers` (tenant).
2. Sanctum for customers, with scoped token abilities.
3. spatie/laravel-permission in the tenant DB; seeded roles Owner, Manager, Order Handler,
   Inventory Staff, Content Editor, Accountant, with a documented permission matrix.
4. Mandatory 2FA (TOTP, encrypted recovery codes) for staff and platform users; session
   timeout.
5. Argon2id hashing, breached-password check, throttling, lockout, `login_audits` for success
   and failure.
6. spatie/laravel-activitylog enabled, append-only.
7. Policy scaffolding, with the rule that policies are checked **inside Actions**.

**Tests**

| Test | Asserts |
|---|---|
| `feature/GuardSeparationTest` | staff credentials fail on the customer guard and vice versa; a platform user cannot authenticate into a tenant |
| `isolation/CrossTenantAuthTest` | tenant A staff credentials fail on brand B's admin domain; a tenant A customer token is rejected on brand B's API |
| `feature/TwoFactorTest` | a staff login without a confirmed second factor cannot reach any admin page |
| `feature/LoginThrottleTest` | lockout after the configured failures, per IP and per account; every attempt is audited |
| `feature/PasswordPolicyTest` | Argon2id in use; a breached password is rejected at registration and change |
| `feature/PolicyEnforcementTest` | an Action denies an unauthorised actor even when called directly, bypassing the UI |
| `feature/ActivityLogAppendOnlyTest` | audit rows cannot be updated or deleted by the app role |

---

## Milestone 1.6 — Filament shells: tenant admin and platform panel

**Build**

1. Tenant admin panel on `admin.<brand>`, inside the resolved tenancy context. **Filament's
   built-in tenancy is not used** (spec §4.5).
2. Platform panel on the platform host: companies, brands, domains, plan/feature flags,
   schema version, health, last backup.
3. Create/suspend/activate tenants from the panel, **calling the same Actions** as the Artisan
   commands.
4. Impersonation into a tenant admin: mandatory reason, time-limited, recorded in
   `impersonation_sessions` and mirrored into the tenant's own activity log.
5. Multi-brand owner access: a landlord identity linked per brand, with a brand switcher that
   hands off via a short-lived, single-use signed token — no cross-database session.
6. Filament Shield wired to the permission set.

**Tests**

| Test | Asserts |
|---|---|
| `feature/AdminPanelTenancyTest` | the admin panel operates on the resolved tenant's database only |
| `feature/PlatformPanelAccessTest` | only platform users reach the landlord panel; tenant staff get 404, not 403, on the platform host |
| `feature/ImpersonationTest` | a reason is required; the session expires; both audit trails record it; it cannot target a different tenant than the one selected |
| `feature/BrandSwitcherTest` | a company owner reaches both of their brands; the hand-off token is single-use and expires; **no data from brand B appears while in brand C** |
| `feature/BrandSwitcherDenialTest` | an owner of company Y cannot reach a brand of company X by forging a token |

---

## Milestone 1.7 — Settings and feature flags

**Build**

1. `settings` (grouped key/value `jsonb`, encrypted where flagged) with a typed accessor layer
   and a cached read path.
2. Effective feature flags = plan defaults overridden by `tenant_feature_flags`, resolved once
   per request and cached per tenant.
3. Storefront config endpoint (`GET /api/v1/storefront/config`) serving theme id, tokens,
   flags, navigation, locale, currency.
4. A flag-gating middleware/attribute so a disabled feature returns `403 feature_disabled`
   rather than a 404 or a blank page.

**Tests**

| Test | Asserts |
|---|---|
| `feature/SettingsTest` | defaults seed per tenant; encrypted settings are unreadable raw; writes bust the cache |
| `isolation/SettingsIsolationTest` | changing a setting in brand B does not change brand C of the same company |
| `feature/FeatureFlagTest` | plan default is overridden by the tenant row; a gated endpoint returns `403 feature_disabled` |
| `feature/StorefrontConfigTest` | the config endpoint returns the tenant's own theme, flags and currency, and 404s on an unknown host |

---

## Milestone 1.8 — Tenant isolation test suite (the gate)

This is not new functionality; it is the **release blocker** that proves everything above.
The ten channels are specified in [tenancy.md](../architecture/tenancy.md) §7: database, API,
admin, cache, search, storage, queues, scheduler, **two brands of the same company**, and both
topologies.

**Build**

1. A `Pest` group `isolation` with a three-tenant fixture: company X with brand A, company Y
   with brands B and C.
2. A CI job that runs the group as its own required check, so an isolation failure is
   distinguishable from an ordinary test failure at a glance.
3. `docs/runbooks/verify-isolation.md` for running it against a staging environment.

**Done when** all ten channels pass, including brand B vs brand C, in both topologies.

---

## Sequencing and dependencies

```text
1.1 app + modules + gates
      ↓
1.2 landlord DB + tenancy core ──────┐
      ↓                              │
1.3 Redis/Horizon/scheduler/outbox   │
      ↓                              │
1.4 lifecycle commands ──────────────┤ (1.4 needs 1.2; ledger lockdown needs 1.2 migrations)
      ↓                              │
1.5 identity ────────────────────────┤
      ↓                              │
1.6 Filament shells ─────────────────┤ (needs 1.4 Actions and 1.5 permissions)
      ↓                              │
1.7 settings + flags ────────────────┘
      ↓
1.8 isolation suite  →  PHASE 1 CHECKPOINT
```

1.3 and 1.4 can overlap once 1.2 is merged. 1.6 cannot start before 1.4 and 1.5, because the
panel is a thin caller of their Actions.

## Deliberately out of scope for Phase 1

No catalog, no products, no orders, no payments, no storefront. The temptation to "just add a
products table to have something to show" is the exact mistake this ordering exists to
prevent: every business table must be created in a codebase where tenancy, permissions, the
outbox and the ledger lockdown already work.

## Risks carried into Phase 1

| Risk | Handling |
|---|---|
| The local machine has no Docker, PostgreSQL or PHP 8.4 (see the Phase 0 checkpoint) | **Must be resolved before 1.1 starts.** Milestone 1.1 cannot be verified on the current XAMPP/MySQL setup. |
| Transaction-pooling constraints surprise us later | PgBouncer runs locally from day one, so session-state assumptions break in development rather than in production |
| stancl/tenancy version drift against the latest Laravel | Pin versions in `composer.lock`; verify the multi-database + queue bootstrapper combination in 1.2 before building on it |
| Isolation tests become slow and get skipped | Keep the fixture to three tenants; run the group in parallel; it stays a required check |
