-- Local development roles, mirroring the production privilege model documented in
-- docs/architecture/database-roles.md (database/setup/bootstrap-database.sql in production).
--
--   store_migrator  owns the schema; used by `php artisan app:migrate`
--   store_app       DML only; serves requests, jobs and commands
--   backup_reader   SELECT only
--
-- Passwords here are local-only development values. Production credentials come from the
-- secret manager and are never committed (spec §8).

CREATE ROLE store_migrator LOGIN PASSWORD 'local-store-migrator'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE store_app LOGIN PASSWORD 'local-store-app'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE backup_reader LOGIN PASSWORD 'local-backup-reader'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

-- The database was created by POSTGRES_DB; hand it to the migrator and lock out PUBLIC.
ALTER DATABASE store OWNER TO store_migrator;
REVOKE CONNECT ON DATABASE store FROM PUBLIC;
GRANT  CONNECT ON DATABASE store TO store_app, store_migrator, backup_reader;

\connect store

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO store_migrator;
GRANT USAGE ON SCHEMA public TO store_app, backup_reader;

-- The application role gets DML on current and future tables, never DDL.
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO store_app;
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO store_app;
ALTER DEFAULT PRIVILEGES FOR ROLE store_migrator IN SCHEMA public
  GRANT SELECT ON TABLES TO backup_reader;
