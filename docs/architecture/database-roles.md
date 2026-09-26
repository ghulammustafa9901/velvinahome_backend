# PostgreSQL Role & Privilege Design

Implements spec §6.3 (database standards) and §8 (security, database level). Isolation in
this platform is **by separate database**, and the privilege model is what makes that
isolation real rather than merely conventional.

## 1. Role inventory

| Role | Type | Used by | Privileges |
|---|---|---|---|
| `platform_provisioner` | LOGIN | `tenant:create` / `tenant:delete` only, credentials held outside the app runtime | `CREATEDB`, `CREATEROLE`. **Not** superuser. Never used to serve a request. |
| `landlord_app` | LOGIN | the application, landlord connection | DML on landlord tables; no DDL |
| `landlord_migrator` | LOGIN | deploys only | owns the landlord schema; DDL |
| `tenant_<slug>_app` | LOGIN | the application, tenant connection | DML on that one tenant database only; no DDL |
| `tenant_<slug>_migrator` | LOGIN | deploys only | owns that tenant's schema; DDL |
| `metrics_reader` | LOGIN | the cross-tenant aggregation job | `SELECT` on a narrow allowlist of aggregate-source columns |
| `backup_reader` | LOGIN | backup tooling | `SELECT` only, all tables of the target database |

Hard rules:

- **No tenant application role has `SUPERUSER`, `CREATEDB`, `CREATEROLE` or `BYPASSRLS`.**
- Application roles hold **no DDL** anywhere. Schema changes only happen as the migrator role
  during a deploy, which is what makes "the app cannot alter its own schema" true.
- `platform_provisioner` credentials live in the deployment secret store, not in the
  application environment used to serve traffic.
- Every connection uses TLS (`sslmode=verify-full` in production). Databases are never
  publicly exposed; connections arrive via PgBouncer on a private network.

## 2. Per-tenant database creation (run by `platform_provisioner`)

`tenant:create` executes this template. `:db`, `:app_role`, `:mig_role` are derived from the
tenant slug; passwords are generated (32+ bytes, CSPRNG), stored encrypted in the landlord
`tenant_databases` table, and never logged or printed.

```sql
-- 1. Roles
CREATE ROLE :mig_role LOGIN PASSWORD :'mig_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE :app_role LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;

-- 2. Database, owned by the migration role
CREATE DATABASE :db OWNER :mig_role ENCODING 'UTF8' LC_COLLATE 'en_US.UTF-8' TEMPLATE template0;

-- 3. Nobody connects by default; only this tenant's own roles may
REVOKE CONNECT ON DATABASE :db FROM PUBLIC;
GRANT  CONNECT ON DATABASE :db TO :app_role, :mig_role, :backup_reader;

-- 4. Inside the new database: the public schema belongs to the migrator
\connect :db
REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO :mig_role;
GRANT USAGE ON SCHEMA public TO :app_role;

-- 5. The app gets DML on current and future tables, never DDL
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO :app_role;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA public TO :app_role;
ALTER DEFAULT PRIVILEGES FOR ROLE :mig_role IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :app_role;
ALTER DEFAULT PRIVILEGES FOR ROLE :mig_role IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO :app_role;
```

Because `CONNECT` is revoked from `PUBLIC` and granted only to that tenant's own roles,
**tenant A's application role cannot even open a connection to tenant B's database.** That
is asserted directly by isolation test #1 (see [tenancy.md](tenancy.md) §7).

## 3. Append-only ledgers enforced by grants (FINAL — spec §6.3)

After every tenant migration run, the migrator executes the ledger lockdown. This is part of
`tenants:migrate`, not a manual step, so a newly added ledger table can never be left
writable.

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON
  inventory_movements,
  payment_transactions,
  webhook_events,
  order_state_transitions,
  activity_log,
  login_audits,
  stock_snapshots_daily
FROM :app_role;
```

The application role keeps `INSERT` and `SELECT` only. An attempt to update or delete a
ledger row fails at the database, regardless of application bugs, a compromised application
account, or someone using a console. A Pest test asserts each ledger table rejects
`UPDATE`/`DELETE` under the application role, and a second test asserts the revoke list
covers every table declared append-only in the code (so adding a ledger without locking it
down fails CI).

The same lockdown applies in the landlord database to `platform_activity_log` and
`impersonation_sessions`.

## 4. Landlord database

```sql
CREATE ROLE landlord_migrator LOGIN PASSWORD :'...' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE landlord_app      LOGIN PASSWORD :'...' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE platform_landlord OWNER landlord_migrator ENCODING 'UTF8' TEMPLATE template0;
REVOKE CONNECT ON DATABASE platform_landlord FROM PUBLIC;
GRANT  CONNECT ON DATABASE platform_landlord TO landlord_app, landlord_migrator, backup_reader;
```

`landlord_app` has **no `CONNECT` on any tenant database**, and tenant application roles have
no `CONNECT` on the landlord database. The only component that holds both sets of credentials
is the provisioner path, which is not part of request handling.

Credential columns in `tenant_databases` use Laravel encrypted casts, so the landlord
database alone is not enough to reach a tenant database — `APP_KEY` is also required.

## 5. Connection pooling (PgBouncer)

- **Transaction pooling** mode. It suits many databases with modest concurrency each, which
  is exactly the tenant-per-database shape.
- One PgBouncer pool **per tenant database and role**; `max_db_connections` capped per tenant
  so one busy brand cannot exhaust the server on behalf of the others.
- Transaction pooling forbids session-level state, so the application must not rely on
  session `SET`, advisory locks held across statements, or `LISTEN`/`NOTIFY`. Row locks inside
  a transaction (`SELECT ... FOR UPDATE`) are unaffected, which is what the inventory design
  needs.
- Prepared-statement emulation stays off in PDO for pooled connections
  (`PDO::ATTR_EMULATE_PREPARES` handling is documented in the connection config).
- Migrations connect **directly**, bypassing PgBouncer, because transactional DDL and
  advisory locks need a session connection.

## 6. Concurrency rules the privileges assume

- Multi-row business operations run in a transaction (`READ COMMITTED` default).
- Concurrency-sensitive rows take row locks: `SELECT ... FOR UPDATE` on the `stock_levels`
  row, or an atomic conditional `UPDATE ... WHERE on_hand - reserved >= :qty` — never a
  read-then-write without a lock.
- Lock ordering is documented per Action to avoid deadlocks (stock levels are always locked
  in ascending `variant_id, location_id` order).
- `statement_timeout` and `lock_timeout` are set per connection role: short for web requests,
  longer for queue workers, longest for migrations and reports.

## 7. Row-level security

Not required in v1 (spec §6.3): isolation comes from separate databases plus `CONNECT`
grants, which is a stronger and simpler guarantee than in-database RLS policies. The landlord
database is deliberately kept free of any table that could hold tenant data, so there is no
place where RLS would be the last line of defence. Revisit only if a future feature forces
shared-database data (which would require an ADR).

## 8. What gets verified in CI

1. A tenant application role cannot `CONNECT` to another tenant's database.
2. A tenant application role cannot run DDL (`CREATE TABLE` fails).
3. Every append-only table rejects `UPDATE` and `DELETE` under the application role.
4. The append-only revoke list matches the set of tables marked append-only in code.
5. `landlord_app` cannot connect to any tenant database, and no tenant role can connect to
   the landlord database.
6. No role used at runtime has `SUPERUSER`, `CREATEDB` or `CREATEROLE`.
