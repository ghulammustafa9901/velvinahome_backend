# ADR-0001: Multi-database tenancy with stancl/tenancy

- **Status:** Superseded by [ADR-0020](0020-single-store-application-without-multi-tenancy.md) (single-store application, 2026-10-03)
- **Date:** 2026-09-26
- **Spec reference:** §4 (FINAL), §4.5 (FINAL)

## Context

One tenant is one brand, one website, one admin and **one PostgreSQL database** (spec §4.1).
A company may own several brands; each brand is still a separate tenant. The platform must
scale from 3 to 50+ tenants without architectural change, and must support both a shared
installation serving many tenants and a dedicated installation serving one, by configuration
only.

Retrofitting tenancy later is precisely the rebuild this project exists to avoid, so every
tenant-aware concern — database, cache, queues, storage, search, sessions, rate limits, logs,
mail — has to be solved once, in Phase 1, before any business module exists.

## Decision

Use **stancl/tenancy in multi-database mode**, with:

- Tenant resolution by **request hostname** against the landlord `domains` table. Unknown
  host returns 404 with no fallback tenant.
- The full bootstrapper set enabled: database, cache, filesystem, queue, plus custom
  bootstrappers for Typesense collection prefixing and log/Sentry tenant context.
- Tenant id = ULID, reused as the suffix in the database name, cache prefix, storage path and
  Typesense collection name, so every subsystem's namespace is derivable from one value.
- A `Platform` module as the only code that touches the landlord connection.
- Topology chosen by configuration (`TENANCY_TOPOLOGY=shared|dedicated`), with the same
  bootstrappers running in both.

**Filament's built-in multi-tenancy feature is not used** (spec §4.5). It models "teams"
inside a single database, which is the opposite of the isolation this platform sells. Both
Filament panels instead run inside the tenancy context already resolved from the domain.

## Consequences

### Positive

- Isolation is structural, not conventional: a cross-tenant query is not merely forbidden,
  it is impossible, because the tenant's DB role has no `CONNECT` on any other tenant
  database (see [database-roles.md](../architecture/database-roles.md)).
- A single tenant can be exported, restored, or moved to a dedicated server without touching
  anyone else's data — which is also what makes "client exit" a clean operation.
- Per-tenant backup and point-in-time recovery are natural units.
- A noisy tenant's data volume does not slow down other tenants' queries or indexes.

### Negative / costs

- Migrations must run across every tenant database on every deploy; a failure on one tenant
  blocks the release. `tenants:migrate` must report per tenant and be transactional.
- Connection count grows with tenant count — this is why **PgBouncer is mandatory**, in
  transaction-pooling mode, with a per-tenant connection cap.
- Cross-tenant reporting cannot be a query; it must be a scheduled aggregation into the
  landlord DB, limited to non-sensitive figures.
- Every job, command and scheduled task must carry tenant context explicitly. A job that
  loses context must fail loudly rather than run somewhere plausible.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| A queued job runs in the wrong tenant | Tenant id in every job payload; a tenant-only queue rejects a job without context; isolation test #7 |
| Schema drift between tenants | All tenants always run the same version; `tenant_migration_runs` records per-tenant state; a drift check runs in CI and in the health job |
| Connection exhaustion at 50+ tenants | PgBouncer transaction pooling, per-tenant `max_db_connections`, load test in Phase 7 |
| Provisioning half-fails | `tenant:create` is idempotent with recorded `provisioning_state`; re-runnable steps |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Single database with a `tenant_id` column | Spec-forbidden, and one missing `where` clause leaks another brand's orders. No clean per-tenant backup, restore or exit. |
| Schema-per-tenant in one PostgreSQL database | Explicitly excluded by spec §4.1. Weaker privilege separation, and `pg_dump` per schema plus migration tooling gets awkward at 50+ schemas. |
| Filament's built-in tenancy | Single-database team model; wrong isolation model (spec §4.5). |
| A hand-rolled tenancy layer | Resolution is the easy part; tenant-aware cache, queues, storage and filesystem bootstrapping is where the bugs live. No reason to re-derive a well-tested package. |
