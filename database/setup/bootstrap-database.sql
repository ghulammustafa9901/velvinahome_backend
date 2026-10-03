-- Bootstraps the store database and its PostgreSQL roles.
--
-- Run once per environment as a superuser, before the first `php artisan app:migrate`:
--
--   psql -U postgres -h 127.0.0.1 -f database/setup/bootstrap-database.sql \
--        -v app_password="'...'" -v migrator_password="'...'" -v backup_password="'...'"
--
-- Optional: -v db=store_test for the test database (default: store). Run it again with another
-- db name to add a database; the roles are shared and never re-created.
--
-- Idempotent: safe to re-run. Re-running rotates nothing, so password rotation is a separate
-- documented procedure (docs/runbooks/rotate-secrets.md).
--
-- The privilege model (docs/architecture/database-roles.md), in short:
--   store_app       DML only, on this database only; serves requests, jobs and commands
--   store_migrator  owns the schema; used only by `app:migrate` during deploys
--   backup_reader   SELECT only; used by backups
-- No role here is a superuser, and none may create databases or roles. Append-only ledgers
-- additionally lose UPDATE/DELETE/TRUNCATE for store_app after every migration run.
--
-- Passwords are passed in as psql variables. Nothing in this file is a credential.
-- Role creation goes through SELECT ... \gexec rather than a DO block, because psql does not
-- substitute :variables inside dollar-quoted strings.

\set ON_ERROR_STOP on

\if :{?db}
\else
\set db store
\endif

-- ---------------------------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------------------------

SELECT format('CREATE ROLE store_migrator LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS', :'migrator_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'store_migrator')
\gexec

SELECT format('CREATE ROLE store_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'store_app')
\gexec

SELECT format('CREATE ROLE backup_reader LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS', :'backup_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'backup_reader')
\gexec

-- ---------------------------------------------------------------------------------------
-- Database
-- ---------------------------------------------------------------------------------------

-- CREATE DATABASE cannot run inside a transaction, hence \gexec here too.
SELECT format('CREATE DATABASE %I OWNER store_migrator ENCODING ''UTF8'' TEMPLATE template0', :'db')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db')
\gexec

-- Nobody connects by default; only the store's own roles may.
REVOKE CONNECT ON DATABASE :"db" FROM PUBLIC;
GRANT  CONNECT ON DATABASE :"db" TO store_app, store_migrator, backup_reader;

\connect :db

-- The public schema belongs to the migrator. PUBLIC gets nothing.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO store_migrator;
GRANT USAGE ON SCHEMA public TO store_app, backup_reader;

-- Existing objects (on a re-run against an already-migrated database).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO store_app;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA public TO store_app;
GRANT SELECT                         ON ALL TABLES    IN SCHEMA public TO backup_reader;

-- Future objects created by the migrator: DML for the app, SELECT for backups, never DDL.
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO store_app;
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO store_app;
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
    GRANT SELECT ON TABLES TO backup_reader;

-- ---------------------------------------------------------------------------------------
-- Verification
-- ---------------------------------------------------------------------------------------

\echo '--- roles (rolsuper/createdb/createrole must be f for every role) ---'
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole
FROM pg_roles
WHERE rolname IN ('store_migrator', 'store_app', 'backup_reader')
ORDER BY rolname;

\echo '--- database ---'
SELECT datname, pg_get_userbyid(datdba) AS owner, pg_encoding_to_char(encoding) AS encoding
FROM pg_database
WHERE datname = :'db';
