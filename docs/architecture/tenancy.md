# Tenancy Design

Spec §4 is the most important section of the project. Tenancy is built in **Phase 1,
before any business module**, and every module is tenant-aware from its first line.

Package: **stancl/tenancy in multi-database mode** (FINAL). Filament's built-in
multi-tenancy feature is **not used** — it is a single-database "team" model and the wrong
model here. See [ADR-0001](../adr/0001-multi-database-tenancy-with-stancl-tenancy.md).

## 1. The model

```text
Company X (one brand)            Company Y (two brands)
  └── Brand A                      ├── Brand B
       storefront A                │    storefront B · admin B · database B
       admin A                     └── Brand C
       database A                       storefront C · admin C · database C
```

- **Tenant = brand = website = one PostgreSQL database.**
- Each tenant database uses a single application schema (`public`). PostgreSQL
  schema-per-tenant is **not** used.
- The **landlord database** holds only platform-level data: companies, tenants/brands,
  domains, encrypted tenant DB credentials, platform users and their audit log, release
  and health metadata, and non-sensitive aggregated metrics.
- The landlord database **never** stores any tenant's customer, order, product or payment
  data.
- The schema is **identical** for every tenant. No per-tenant schema customisation, ever.

## 2. Tenant resolution

1. Resolution is by **request hostname**, matched against the landlord `domains` table.
2. Each tenant has a storefront domain (`prifit.com`), an admin domain
   (`admin.prifit.com`) and an API host (`api.prifit.com`) — see
   [ADR-0002](../adr/0002-api-host-strategy.md).
3. An **unknown host returns 404** and never falls back to a default tenant.
4. Tenant identity is **never** taken from user-supplied input alone — not a query
   parameter, not a header, not the request body. Internal Next.js to API calls carry a
   signed service header that is verified server-side and is only trusted in addition to
   the hostname, never instead of it.
5. Local development uses `*.localhost` hostnames (e.g. `prifit.localhost`,
   `admin.prifit.localhost`).

### Domain kinds

`domains.kind` is one of `storefront`, `admin`, `api`. The middleware stack selected for a
request is derived from the resolved domain's kind, so an admin panel can never be served
on a storefront host and vice versa.

## 3. Everything is tenant-aware (FINAL — spec §4.4)

| Concern | Implementation |
|---|---|
| Database | `DatabaseTenancyBootstrapper` switches the `tenant` connection after resolution. The tenant DB user cannot reach any other tenant database (see [database-roles.md](database-roles.md)). |
| Cache | `CacheTenancyBootstrapper` — every key prefixed `t:{tenant_id}:`. Tag-based invalidation stays inside the prefix. |
| Queues | `QueueTenancyBootstrapper` — every job carries `tenant_id` in its payload and re-initialises tenancy on handling. A job without tenant context on a tenant-only queue fails loudly rather than running in the wrong tenant. |
| Horizon | A shared worker pool is fine; correctness comes from per-job tenant context. Queues: `critical`, `default`, `notifications`, `search`, `media`, `reports`. |
| Scheduler | One landlord-level schedule iterates active tenants and dispatches tenant-scoped jobs. A tenant task failure never aborts the loop. |
| Storage | `FilesystemTenancyBootstrapper` — per-tenant path prefix in the R2 bucket (`tenants/{tenant_id}/...`). See [ADR-0004](../adr/0004-object-storage-cloudflare-r2.md). |
| Search | Per-tenant Typesense collections (`{tenant_id}_products`). No shared collection. See [ADR-0005](../adr/0005-search-typesense-per-tenant-collections.md). |
| Sessions / cookies | Session cookie scoped to the tenant admin domain; storefront cookies are first-party on the brand domain. |
| Rate limits | Every limiter key includes the tenant id. |
| Logs / Sentry | A middleware plus Monolog processor stamps `tenant_id`, `request_id`, `user_id` on every log line; the Sentry scope is tagged with the tenant. |
| Mail | From-name, from-address, branding and templates resolved from tenant settings. |
| Inbound webhooks | Tenant-specific webhook URLs (`https://api.<brand>/api/v1/webhooks/{provider}`) so routing is by host, plus signature verification. |

## 4. Deployment topologies (FINAL — spec §4.3)

Configuration only, no code differences:

- **Shared** (`TENANCY_TOPOLOGY=shared`): resolution by hostname per request.
- **Dedicated** (`TENANCY_TOPOLOGY=dedicated`, `TENANCY_PINNED_TENANT=<id>`): the
  application boots already in that tenant's context; hostname resolution is bypassed but
  the same bootstrappers run, so all tenant-aware behaviour is identical.

An automated test asserts the same feature suite passes under both settings.

## 5. Tenant lifecycle (spec §4.6)

All commands are **thin callers of Actions** in `Modules\Platform`, so the landlord
Filament panel invokes exactly the same code paths.

| Command | Action | Behaviour |
|---|---|---|
| `company:create` | `CreateCompany` | Landlord record only. No database of its own. |
| `tenant:create` | `CreateTenant` | Creates the brand record and domains; creates the database and a least-privilege DB role; runs tenant migrations; seeds roles, permissions, settings, default tax/shipping, email templates, default pages; creates Typesense collections; sets the storage prefix; creates the owner admin account; prints a go-live checklist. |
| `tenant:suspend` / `tenant:activate` | `SuspendTenant` / `ActivateTenant` | Status change; a suspended storefront serves a maintenance response and admin login is blocked. |
| `tenant:export` | `ExportTenant` | `pg_dump` plus a media manifest, for backup or client exit. |
| `tenant:delete` | `DeleteTenant` | Requires explicit confirmation **and** a fresh verified backup. |
| `tenants:migrate` | `MigrateTenants` | Runs pending migrations on every tenant database as part of every deployment. |

**`tenant:create` must be idempotent and safe to re-run after a partial failure.** It is
built as ordered, individually re-runnable steps with a recorded provisioning state, so a
re-run skips completed steps instead of duplicating them. A failure leaves a clearly
reported partial tenant, never a silently broken one.

## 6. Migrations and versioning (spec §4.7)

- Landlord migrations (`modules/Platform/database/migrations/landlord/`) and tenant
  migrations (`modules/*/database/migrations/tenant/`) are separate and never mixed.
- **All tenants always run the same schema version.** A deployment migrates every tenant;
  a failure on any tenant blocks the release and is reported per tenant.
- Migrations run **inside transactions** (PostgreSQL has transactional DDL), so a failed
  migration rolls back cleanly and never leaves a half-migrated tenant database.
- Documented exception: operations that cannot run in a transaction (e.g.
  `CREATE INDEX CONCURRENTLY`) are written as separate, explicitly non-transactional
  migrations.
- Changes are backward-compatible for zero-downtime deploys: **expand, migrate, contract**
  across releases.

## 7. Isolation guarantees — the Phase 1 test suite (FINAL — spec §4.8)

Automated tests must prove tenant A cannot reach tenant B through **every** channel. These
run in CI on every change and are a release blocker.

| # | Channel | Test |
|---|---|---|
| 1 | Database | Data written in tenant A is absent in tenant B; tenant A's DB role cannot `CONNECT` to tenant B's database. |
| 2 | API | A request to brand B's host with tenant A's customer token is rejected; a ULID from tenant A returns 404 on tenant B. |
| 3 | Admin | Tenant A staff credentials fail on brand B's admin domain; impersonation is scoped and audited. |
| 4 | Cache | The same cache key set in both tenants yields different values; flushing tenant A leaves tenant B intact. |
| 5 | Search | Indexing a product in tenant A leaves tenant B's collection empty; a query on B never returns A's documents. |
| 6 | Storage | Upload paths are prefixed per tenant; tenant A cannot read tenant B's object keys. |
| 7 | Queues | A job dispatched in tenant A writes only to tenant A's database when processed later. |
| 8 | Scheduler | A tenant-scoped scheduled task runs once per active tenant, in the right context, and continues past a failing tenant. |
| 9 | Two brands, one company | All of the above hold between brands B and C of the same company — the case most likely to be got wrong. |
| 10 | Topology | The suite passes in both shared and dedicated topology configuration. |

## 8. Platform (landlord) control panel (spec §4.9)

A separate Filament panel on the platform's own domain, for the Platform Owner's team only:

- Companies list, each with its brands.
- Tenants list: company, status, domains, plan and feature flags, current schema version,
  health, last backup.
- Create / suspend / activate tenants — calling the same Actions as the Artisan commands.
- **Impersonation / support access** into a tenant admin, always logged with who, when and
  why, time-limited, and surfaced in that tenant's own activity log.
- Aggregated, **non-sensitive** cross-tenant metrics (e.g. orders per day) collected into
  the landlord DB by a scheduled job — never a live cross-database query.

## 9. Multi-brand owner access (DEFAULT — spec §4.1a)

A company owner may access every brand of their company from one identity, with a brand
switcher in the admin.

- Implemented as a **landlord-level identity** (`company_owner_identities`) linked to a
  staff account inside each brand's tenant database.
- Permissions remain **per brand**; all data stays in each brand's own database.
- The switcher redirects to the other brand's admin domain with a short-lived, single-use,
  signed hand-off token. There is no cross-database session and no shared query.
- Staff who belong to a single brand are unaffected.

**Shared stock between brands is not supported in v1.** Two brands of one company keep
separate inventory. Any future shared-stock feature is an explicit sync (ADR required),
never a cross-database query in a request.
