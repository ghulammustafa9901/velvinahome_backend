<?php

declare(strict_types=1);

namespace Modules\Platform\Support\Provisioning;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Creates and removes tenant databases with the privilege model in
 * docs/architecture/database-roles.md §2.
 *
 * Runs as `platform_provisioner`, which holds CREATEDB and CREATEROLE but is not a
 * superuser. Every statement is written to be safe to repeat, because `tenant:create` must be
 * re-runnable after a failure at any point (spec §4.6).
 *
 * DDL has no parameter binding for identifiers, so identifiers come only from
 * TenantDatabaseNames (validated) and are always double-quoted; passwords are passed through
 * the driver's own literal quoting.
 */
final class PostgresProvisioner
{
    private const PROVISIONER_CONNECTION = 'provisioner';

    private const SETUP_CONNECTION = 'tenant_provisioning_setup';

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  array{host: string, port: int}  $server
     */
    public function createDatabase(TenantDatabaseNames $names, string $appPassword, string $migratorPassword, array $server): void
    {
        $provisioner = $this->provisioner();

        $this->ensureRole($provisioner, $names->migratorRole, $migratorPassword);
        $this->ensureRole($provisioner, $names->appRole, $appPassword);

        // PostgreSQL 16+: creating a role grants the creator ADMIN only. Owning a database
        // as that role (and granting on it) additionally needs SET and INHERIT.
        $provisioner->statement(sprintf(
            'GRANT %s TO CURRENT_USER WITH INHERIT TRUE, SET TRUE',
            $this->ident($names->migratorRole),
        ));

        if (! $this->databaseExists($names->database)) {
            $provisioner->statement(sprintf(
                "CREATE DATABASE %s OWNER %s ENCODING 'UTF8' TEMPLATE template0",
                $this->ident($names->database),
                $this->ident($names->migratorRole),
            ));
        }

        // Nobody connects by default; only this tenant's own roles (and backups) may. This
        // is what stops tenant A's application role from even opening tenant B's database.
        $provisioner->statement(sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $this->ident($names->database)));
        $provisioner->statement(sprintf(
            'GRANT CONNECT ON DATABASE %s TO %s, %s, %s',
            $this->ident($names->database),
            $this->ident($names->appRole),
            $this->ident($names->migratorRole),
            $this->ident($this->backupRole()),
        ));

        $this->configureSchema($names, $migratorPassword, $server);
    }

    public function dropDatabase(TenantDatabaseNames $names): void
    {
        $provisioner = $this->provisioner();

        // Owning the database needs the migration role; ending the brand's open sessions (queue
        // workers, web requests) for DROP ... WITH (FORCE) needs the privileges of both roles.
        foreach ([$names->migratorRole, $names->appRole] as $role) {
            if ($this->roleExists($role)) {
                $provisioner->statement(sprintf('GRANT %s TO CURRENT_USER WITH INHERIT TRUE, SET TRUE', $this->ident($role)));
            }
        }

        $provisioner->statement(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $this->ident($names->database)));
        $provisioner->statement(sprintf('DROP ROLE IF EXISTS %s', $this->ident($names->appRole)));
        $provisioner->statement(sprintf('DROP ROLE IF EXISTS %s', $this->ident($names->migratorRole)));
    }

    /**
     * An empty database owned by the tenant's migration role, reachable by that role only —
     * the target of a restore drill. The name must be a validated identifier.
     */
    public function createScratchDatabase(TenantDatabaseNames $names, string $database): void
    {
        $provisioner = $this->provisioner();

        $provisioner->statement(sprintf('GRANT %s TO CURRENT_USER WITH INHERIT TRUE, SET TRUE', $this->ident($names->migratorRole)));
        $provisioner->statement(sprintf(
            "CREATE DATABASE %s OWNER %s ENCODING 'UTF8' TEMPLATE template0",
            $this->ident($database),
            $this->ident($names->migratorRole),
        ));
        $provisioner->statement(sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $this->ident($database)));
        $provisioner->statement(sprintf('GRANT CONNECT ON DATABASE %s TO %s', $this->ident($database), $this->ident($names->migratorRole)));
    }

    public function dropScratchDatabase(TenantDatabaseNames $names, string $database): void
    {
        $this->provisioner()->statement(sprintf('GRANT %s TO CURRENT_USER WITH INHERIT TRUE, SET TRUE', $this->ident($names->migratorRole)));
        $this->provisioner()->statement(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $this->ident($database)));
    }

    /**
     * Re-apply the schema privileges after a restore (a dump is restored without them).
     *
     * @param  array{host: string, port: int}  $server
     */
    public function reapplyPrivileges(TenantDatabaseNames $names, string $migratorPassword, array $server): void
    {
        $this->configureSchema($names, $migratorPassword, $server);
    }

    public function databaseExists(string $database): bool
    {
        return $this->provisioner()
            ->table('pg_database')
            ->where('datname', $database)
            ->exists();
    }

    public function roleExists(string $role): bool
    {
        return $this->provisioner()
            ->table('pg_roles')
            ->where('rolname', $role)
            ->exists();
    }

    /**
     * Inside the new database, as its owner: the public schema belongs to the migration
     * role, the application role gets DML on current and future tables and nothing else.
     *
     * @param  array{host: string, port: int}  $server
     */
    private function configureSchema(TenantDatabaseNames $names, string $migratorPassword, array $server): void
    {
        /** @var array<string, mixed> $template */
        $template = config('database.connections.tenant_template');

        config(['database.connections.'.self::SETUP_CONNECTION => array_merge($template, [
            'host' => $server['host'],
            'port' => $server['port'],
            'database' => $names->database,
            'username' => $names->migratorRole,
            'password' => $migratorPassword,
        ])]);

        try {
            $owner = $this->db->connection(self::SETUP_CONNECTION);
            $app = $this->ident($names->appRole);
            $backup = $this->ident($this->backupRole());

            $owner->statement('REVOKE ALL ON SCHEMA public FROM PUBLIC');
            $owner->statement(sprintf('ALTER SCHEMA public OWNER TO %s', $this->ident($names->migratorRole)));
            $owner->statement(sprintf('GRANT USAGE ON SCHEMA public TO %s, %s', $app, $backup));
            $owner->statement(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %s', $app));
            $owner->statement(sprintf('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %s', $app));
            $owner->statement(sprintf('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %s', $app));
            $owner->statement(sprintf('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %s', $app));
            $owner->statement(sprintf('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO %s', $backup));
        } finally {
            $this->db->purge(self::SETUP_CONNECTION);
            config(['database.connections.'.self::SETUP_CONNECTION => null]);
        }
    }

    private function ensureRole(Connection $provisioner, string $role, string $password): void
    {
        $quotedPassword = $provisioner->getPdo()->quote($password);

        if ($this->roleExists($role)) {
            // Re-running provisioning re-applies the password stored for the tenant, so the
            // landlord record and the role can never drift apart. Only a superuser may even
            // mention SUPERUSER/BYPASSRLS in ALTER ROLE, so this touches the password only.
            $provisioner->statement(sprintf('ALTER ROLE %s WITH LOGIN PASSWORD %s', $this->ident($role), $quotedPassword));

            return;
        }

        $provisioner->statement(sprintf(
            'CREATE ROLE %s LOGIN PASSWORD %s NOSUPERUSER NOCREATEDB NOCREATEROLE',
            $this->ident($role),
            $quotedPassword,
        ));
    }

    private function provisioner(): Connection
    {
        return $this->db->connection(self::PROVISIONER_CONNECTION);
    }

    private function backupRole(): string
    {
        return (string) config('platform.provisioning.backup_role');
    }

    private function ident(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
