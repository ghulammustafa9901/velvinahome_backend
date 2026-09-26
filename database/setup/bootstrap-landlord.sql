-- Bootstraps the landlord database and the platform's PostgreSQL roles.
--
-- Run once per environment as a superuser, before the first landlord migration:
--
--   psql -U postgres -h 127.0.0.1 -f database/setup/bootstrap-landlord.sql \
--        -v app_password="'...'" -v migrator_password="'...'" \
--        -v provisioner_password="'...'" -v backup_password="'...'"
--
-- Idempotent: safe to re-run. Re-running rotates nothing, so password rotation is a separate
-- documented procedure (docs/runbooks/rotate-secrets.md).
--
-- The privilege model this implements is documented in docs/architecture/database-roles.md.
-- The short version: application roles get DML and nothing else, migration roles own the
-- schema and are used only during deploys, and the provisioner is the only role that may
-- create databases and roles — and it is still not a superuser.
--
-- Passwords are passed in as psql variables. Nothing in this file is a credential.
--
-- Note on style: role creation goes through SELECT ... \gexec rather than a DO block, because
-- psql does not substitute :variables inside dollar-quoted strings.

\set ON_ERROR_STOP on

-- ---------------------------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------------------------

-- Provisioner: creates tenant databases and tenant roles (tenant:create / tenant:delete).
-- Deliberately NOT a superuser, so a compromised provisioning path cannot take over the
-- cluster or read a tenant database it was not granted.
SELECT format(
    'CREATE ROLE platform_provisioner LOGIN PASSWORD %L NOSUPERUSER CREATEDB CREATEROLE NOBYPASSRLS',
    :'provisioner_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_provisioner')
\gexec

-- Landlord migration role: owns the landlord schema. Used only by deploys.
SELECT format(
    'CREATE ROLE landlord_migrator LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS',
    :'migrator_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'landlord_migrator')
\gexec

-- Landlord application role: DML on landlord tables, no DDL, and no access to any tenant
-- database.
SELECT format(
    'CREATE ROLE landlord_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS',
    :'app_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'landlord_app')
\gexec

-- Backup role: SELECT only, used by backup tooling.
SELECT format(
    'CREATE ROLE backup_reader LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS',
    :'backup_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'backup_reader')
\gexec

-- ---------------------------------------------------------------------------------------
-- Landlord database
-- ---------------------------------------------------------------------------------------

-- CREATE DATABASE cannot run inside a transaction, hence \gexec here too.
SELECT 'CREATE DATABASE platform_landlord OWNER landlord_migrator ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'platform_landlord')
\gexec

-- Nobody connects by default; only the landlord's own roles may.
REVOKE CONNECT ON DATABASE platform_landlord FROM PUBLIC;
GRANT  CONNECT ON DATABASE platform_landlord TO landlord_app, landlord_migrator, backup_reader;

\connect platform_landlord

-- The public schema belongs to the migrator. PUBLIC gets nothing.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO landlord_migrator;
GRANT USAGE ON SCHEMA public TO landlord_app;
GRANT USAGE ON SCHEMA public TO backup_reader;

-- Existing objects (on a re-run against an already-migrated database).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO landlord_app;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA public TO landlord_app;
GRANT SELECT                         ON ALL TABLES    IN SCHEMA public TO backup_reader;

-- Future objects created by the migrator: DML for the app, SELECT for backups, never DDL.
ALTER DEFAULT PRIVILEGES FOR ROLE landlord_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO landlord_app;
ALTER DEFAULT PRIVILEGES FOR ROLE landlord_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO landlord_app;
ALTER DEFAULT PRIVILEGES FOR ROLE landlord_migrator IN SCHEMA public
    GRANT SELECT ON TABLES TO backup_reader;

-- ---------------------------------------------------------------------------------------
-- Verification
-- ---------------------------------------------------------------------------------------

\echo '--- roles (rolsuper/createdb/createrole must be f for every runtime role) ---'
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole
FROM pg_roles
WHERE rolname IN ('platform_provisioner', 'landlord_migrator', 'landlord_app', 'backup_reader')
ORDER BY rolname;

\echo '--- landlord database ---'
SELECT datname, pg_get_userbyid(datdba) AS owner, pg_encoding_to_char(encoding) AS encoding
FROM pg_database
WHERE datname = 'platform_landlord';
