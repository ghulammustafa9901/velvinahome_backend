# PostgreSQL Role & Privilege Design

Implements spec §6.3 (database standards) and §8 (security, database level) for one store
database per installation (ADR-0020). The privilege model — not application discipline — is
what keeps ledgers append-only and the schema out of the application's reach.

## 1. Role inventory

| Role | Used by | Privileges |
|---|---|---|
| `store_app` | the application: requests, jobs, commands (`pgsql` connection) | DML on the store database; **no DDL** |
| `store_migrator` | `php artisan app:migrate` during deploys; backups (`pgsql_migrator` connection) | owns the schema; DDL |
| `backup_reader` | external backup tooling | `SELECT` only |

Hard rules:

- **No role has `SUPERUSER`, `CREATEDB`, `CREATEROLE` or `BYPASSRLS`.** Databases are created
  once, by a superuser, with `database/setup/bootstrap-database.sql`.
- The application role holds **no DDL**. Schema changes only happen as the migrator during a
  deploy, which is what makes "the app cannot alter its own schema" true.
- Every connection uses TLS (`sslmode=verify-full` in production). Databases are never publicly
  exposed; application connections arrive via PgBouncer on a private network.
- Two installations on one PostgreSQL server use different role and database names (the
  bootstrap script takes the database name; roles can be renamed per installation).

## 2. Database creation (`database/setup/bootstrap-database.sql`)

Run once per environment as a superuser, and once more for the restore drill's scratch
database:

```bash
psql -U postgres -f database/setup/bootstrap-database.sql \
     -v app_password="'...'" -v migrator_password="'...'" -v backup_password="'...'"
psql -U postgres -f database/setup/bootstrap-database.sql -v db=store_restore_drill \
     -v app_password="'...'" -v migrator_password="'...'" -v backup_password="'...'"
```

What it does (idempotent):

```sql
CREATE ROLE store_migrator LOGIN ... NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE store_app      LOGIN ... NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE backup_reader  LOGIN ... NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE DATABASE store OWNER store_migrator ENCODING 'UTF8' TEMPLATE template0;

REVOKE CONNECT ON DATABASE store FROM PUBLIC;
GRANT  CONNECT ON DATABASE store TO store_app, store_migrator, backup_reader;

-- inside the database
REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO store_migrator;
GRANT USAGE ON SCHEMA public TO store_app, backup_reader;
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO store_app;
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO store_app;
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
  GRANT SELECT ON TABLES TO backup_reader;
```

## 3. Append-only ledgers enforced by grants (FINAL — spec §6.3)

`app:migrate` runs every pending migration and then the ledger lockdown **in one
transaction**, so a newly added ledger table is locked down in the same transaction that
creates it:

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON <every table a module declares append-only> FROM store_app;
```

Modules declare their ledgers in `appendOnlyTables()` (e.g. `inventory_movements`,
`payment_transactions`, `webhook_events`, `order_state_transitions`, `activity_log`,
`login_audits`, `stock_snapshots_daily`). The application role keeps `INSERT` and `SELECT`
only; an update or delete fails at the database regardless of application bugs, a compromised
application account or a console session.

## 4. Migrations

- `php artisan app:migrate` connects as `store_migrator`, directly (never through PgBouncer:
  transactional DDL needs a session).
- All pending migrations run in **one** transaction: a failure leaves the database exactly as
  it was, the command exits non-zero, and the release is blocked (`deploy/release.sh`).
- A plain `php artisan migrate` on the default connection fails by design: `store_app` has no
  DDL.
- Module default seeders run after the migrations (idempotent, never overwrite store changes).

## 5. Connection pooling (PgBouncer)

- **Transaction pooling** mode for application traffic (`DB_POOLER_HOST`).
- Transaction pooling forbids session-level state, so the application must not rely on
  session `SET`, advisory locks held across statements, or `LISTEN`/`NOTIFY`. Row locks inside
  a transaction (`SELECT ... FOR UPDATE`) are unaffected, which is what the inventory design
  needs.
- PgBouncer ≥ 1.21 with `max_prepared_statements > 0` (PDO uses protocol-level prepared
  statements).

## 6. Concurrency rules the privileges assume

- Multi-row business operations run in a transaction (`READ COMMITTED` default).
- Concurrency-sensitive rows take row locks: `SELECT ... FOR UPDATE` on the `stock_levels`
  row, or an atomic conditional `UPDATE ... WHERE on_hand - reserved >= :qty` — never a
  read-then-write without a lock.
- Stock levels are always locked in ascending `variant_id, location_id` order.

## 7. Row-level security

Not used: one store per database, no shared tables to partition.

## 8. What gets verified in CI (`tests/Feature/Support/DatabasePrivilegeTest.php`, `MigrateTest.php`)

1. The application role cannot run DDL.
2. Every append-only table rejects `UPDATE`, `DELETE` and `TRUNCATE` under the application role,
   and the revoke list covers every table declared append-only in code.
3. Neither the application nor the migration role has `SUPERUSER`, `CREATEDB` or `CREATEROLE`.
4. Application traffic goes through the pooler; migrations go around it.
5. A failing migration among several rolls the whole run back and fails `app:migrate`.
