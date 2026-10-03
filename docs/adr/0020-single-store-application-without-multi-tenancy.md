# ADR-0020: Single-store application; multi-tenancy removed

- **Status:** Accepted
- **Date:** 2026-10-03
- **Spec reference:** master spec v2.0 §1, §4 (FINAL — owner decision)
- **Supersedes:** ADR-0001, ADR-0005, ADR-0012, ADR-0013
- **Amends:** ADR-0002, ADR-0004, ADR-0014, ADR-0016, ADR-0017, ADR-0019
- **Plan and audit:** [docs/plans/remove-multi-tenancy.md](../plans/remove-multi-tenancy.md)

## Context

Phases 1–7 built a multi-tenant platform: one landlord database, one PostgreSQL database per
brand, host-based tenant resolution (stancl/tenancy), tenant-scoped cache, storage, queues and
search, a platform (landlord) panel with impersonation and a brand switcher, and an isolation
test suite.

The owner decided (2026-10-03) that the project is **a normal single-store application with one
PostgreSQL database**. Another client is served by installing the same codebase separately, with
its own database, `.env` and deployment. Everything tenancy existed for is then provided by the
installation boundary itself, and the tenancy machinery is cost without benefit: a landlord
database, provisioning with CREATEDB/CREATEROLE, a context switch on every request and job, and
a whole class of isolation bugs to test for.

## Decision

1. **One installation = one store = one PostgreSQL database.** There is no landlord database,
   no tenant, domain, company or brand model, no tenant resolution, no platform panel, no
   impersonation and no tenancy package. The Platform module is gone.
2. **Store identity is data, not code or tenancy:** name, storefront URL, logo, currency,
   timezone, locale, country, theme, design tokens, SEO, mail sender and feature flags are
   **store settings**, edited in the admin (Settings → Store) and published to the storefront
   by `GET /api/v1/storefront/config`. Payment and courier credentials are entered in the admin
   and stored encrypted in the database. **Secrets and infrastructure** (database, Redis,
   Typesense, object storage, mail transport, APP_KEY, service secrets, hosts) come from `.env`.
   Nothing store-specific is hardcoded.
3. **Shared infrastructure is namespaced per installation**, never per tenant: `REDIS_PREFIX`,
   `HORIZON_PREFIX`, `SCOUT_PREFIX` (collection `{prefix}products`, synonym set
   `{prefix}synonyms`) and `OBJECT_STORAGE_PREFIX` (disks `media` and `private`).
4. **The privilege model stays**, for one database: `store_app` (DML only), `store_migrator`
   (owns the schema), `backup_reader` (SELECT); append-only ledgers lose UPDATE/DELETE/TRUNCATE
   for the application role after every migration run. `app:migrate` runs every pending
   migration in one transaction and blocks the release on failure.
5. **Installation tooling:** `store:install` (idempotent: schema, identity settings, owner
   account, search collection, launch checklist), `db:backup [--drill]` (dump with checksum,
   weekly restore drill into a scratch database), `secrets:reencrypt`.
6. **Operations tooling** (Horizon, API docs) is for signed-in staff with the `system.monitor`
   permission (Owner only by default), on the admin host.
7. **The storefront** serves one store: no host-based resolution, no per-host routing segment,
   unscoped cache tags; the theme is the one named by the `theme.id` setting.
8. **Multi-tenancy must not be re-added.** `tests/Architecture/NoTenancyTest.php` fails the
   build on any tenancy package, `tenancy()`/`tenant()` helper, tenant context class,
   `Modules\Platform` reference or landlord connection.

## Consequences

### Positive

- One database, one connection, no per-request context switch: simpler code, faster requests,
  and no cross-tenant leak is possible by construction.
- Each client gets real isolation (separate database, deployment, secrets and backups) and can
  live on its own server or share infrastructure through the prefixes.
- Provisioning needs no CREATEDB/CREATEROLE role at runtime; the bootstrap SQL runs once.
- The test suite no longer needs per-brand fixture databases.

### Negative / costs

- Shared releases across clients are now N deployments instead of one; a fix reaches each
  client when its installation is deployed.
- Cross-client reporting (the planned landlord metrics) no longer exists; each store reports on
  its own data.
- A company with several brands runs several installations; there is no brand switcher.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Two installations collide on a shared Redis, Typesense or bucket | Per-installation prefixes, documented in `docs/runbooks/install-store.md`; `store:install` warns about an empty `SCOUT_PREFIX` in production |
| Store identity creeps back into code or `.env` | Settings page + the rule in `CLAUDE.md`; review |
| Tenancy is reintroduced "just for one feature" | Architecture guard test; this ADR |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep tenancy, run one tenant per installation ("dedicated topology") | Keeps all the machinery and its failure modes for no benefit |
| Single database with a `store_id` column on every table | A different multi-tenancy, with weaker isolation; not what the owner asked for |
