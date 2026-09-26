-- Local development role setup, mirroring the production privilege model documented in
-- docs/architecture/database-roles.md.
--
-- The point of creating these locally is that tenant:create exercises the same
-- least-privilege path it will use in production: a provisioner role that can create
-- databases and roles but is NOT a superuser, and an application role with no DDL.
--
-- Passwords here are local-only development values. Production credentials come from the
-- secret manager and are never committed (spec §8).

-- Provisioner: creates tenant databases and tenant roles. Used only by tenant:create /
-- tenant:delete, never to serve a request.
CREATE ROLE platform_provisioner LOGIN PASSWORD 'local-provisioner'
  NOSUPERUSER CREATEDB CREATEROLE NOBYPASSRLS;

-- Landlord roles.
CREATE ROLE landlord_migrator LOGIN PASSWORD 'local-landlord-migrator'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE landlord_app LOGIN PASSWORD 'local-landlord-app'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

-- Backup reader: SELECT only, used by backup tooling.
CREATE ROLE backup_reader LOGIN PASSWORD 'local-backup-reader'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

-- The landlord database was created by POSTGRES_DB; hand its schema to the migrator and
-- lock out PUBLIC, exactly as production does.
ALTER DATABASE platform_landlord OWNER TO landlord_migrator;
REVOKE CONNECT ON DATABASE platform_landlord FROM PUBLIC;
GRANT  CONNECT ON DATABASE platform_landlord TO landlord_app, landlord_migrator, backup_reader;

\connect platform_landlord

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO landlord_migrator;
GRANT USAGE ON SCHEMA public TO landlord_app;

-- The application role gets DML on current and future landlord tables, never DDL.
ALTER DEFAULT PRIVILEGES FOR ROLE landlord_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO landlord_app;
ALTER DEFAULT PRIVILEGES FOR ROLE landlord_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO landlord_app;
ALTER DEFAULT PRIVILEGES FOR ROLE landlord_migrator IN SCHEMA public
  GRANT SELECT ON TABLES TO backup_reader;
