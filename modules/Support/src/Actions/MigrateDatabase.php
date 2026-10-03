<?php

declare(strict_types=1);

namespace Modules\Support\Actions;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Support\Contracts\Action;
use Modules\Support\Modules\ModuleRegistry;

/**
 * `app:migrate`: brings the database to the current schema, as the migration role.
 *
 * The whole run — every pending migration, the migrations-table bookkeeping and the ledger
 * lockdown — is ONE PostgreSQL transaction. Laravel wraps each migration in its own
 * transaction; inside ours those become savepoints. So a failure in the third of three
 * pending migrations leaves the database exactly as it was before the run, not two-thirds
 * migrated (spec §6.3), and the release is blocked with the error.
 *
 * The application role has no DDL, so a plain `php artisan migrate` on the default
 * connection fails by design.
 */
final class MigrateDatabase implements Action
{
    public const CONNECTION = 'pgsql_migrator';

    public function __construct(
        private readonly Migrator $migrator,
        private readonly DatabaseManager $db,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @param  list<string>|null  $paths  migration directories; every module's by default
     * @return list<string> the migrations applied by this run
     */
    public function handle(?array $paths = null): array
    {
        $paths ??= [database_path('migrations'), ...$this->migrator->paths()];
        $connection = $this->db->connection(self::CONNECTION);
        $applied = [];

        try {
            $connection->transaction(function () use ($connection, $paths, &$applied): void {
                $this->migrator->usingConnection(self::CONNECTION, function () use ($paths, &$applied): void {
                    if (! $this->migrator->repositoryExists()) {
                        $this->migrator->getRepository()->createRepository();
                    }

                    $applied = $this->migrator->run($paths);
                });

                $this->lockDownLedgers($connection, (string) config('database.connections.pgsql.username'));
            });
        } finally {
            $this->db->purge(self::CONNECTION);
        }

        /** @var list<string> $applied */
        return array_values(array_map(fn (string $path): string => basename($path, '.php'), $applied));
    }

    /**
     * Append-only ledgers are enforced by the database, not by code review: the application
     * role keeps INSERT and SELECT only (database-roles.md). Re-applied on every run, so a
     * ledger table added in this release is locked down in the same transaction that
     * creates it.
     */
    private function lockDownLedgers(Connection $connection, string $appRole): void
    {
        $existing = $connection->table('pg_tables')
            ->where('schemaname', 'public')
            ->pluck('tablename')
            ->all();

        foreach ($this->modules->appendOnlyTables() as $table) {
            if (! in_array($table, $existing, true)) {
                continue;
            }

            $connection->statement(sprintf(
                'REVOKE UPDATE, DELETE, TRUNCATE ON TABLE "%s" FROM "%s"',
                str_replace('"', '""', $table),
                str_replace('"', '""', $appRole),
            ));
        }
    }
}
