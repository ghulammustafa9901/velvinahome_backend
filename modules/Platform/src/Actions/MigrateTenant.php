<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantMigrationRun;
use Modules\Platform\Tenancy\TenantDatabaseConfig;
use Modules\Support\Contracts\Action;
use Modules\Support\Modules\ModuleRegistry;
use Throwable;

/**
 * Brings one tenant database to the current schema, as that tenant's migration role.
 *
 * The whole run — every pending migration, the migrations-table bookkeeping and the ledger
 * lockdown — is ONE PostgreSQL transaction. Laravel wraps each migration in its own
 * transaction; inside ours those become savepoints. So a failure in the third of three
 * pending migrations leaves the database exactly as it was before the run, not two-thirds
 * migrated (spec §4.7, §6.3, §15).
 *
 * The outcome is recorded in the landlord `tenant_migration_runs`, which is how a failed
 * tenant blocks a release and is reported by name.
 */
final class MigrateTenant implements Action
{
    public const CONNECTION = 'tenant_migrator';

    public function __construct(
        private readonly Migrator $migrator,
        private readonly DatabaseManager $db,
        private readonly ModuleRegistry $modules,
    ) {}

    public function handle(Tenant $tenant): TenantMigrationRun
    {
        $run = TenantMigrationRun::query()->create([
            'tenant_id' => $tenant->id,
            'release_version' => (string) config('platform.release'),
            'status' => 'running',
            'started_at' => now(),
        ]);

        /** @var TenantDatabaseConfig $database */
        $database = $tenant->database();

        config(['database.connections.'.self::CONNECTION => $database->migratorConnection()]);
        $this->db->purge(self::CONNECTION);

        try {
            $applied = [];
            $connection = $this->db->connection(self::CONNECTION);

            $connection->transaction(function () use ($connection, $database, &$applied): void {
                $this->migrator->usingConnection(self::CONNECTION, function () use (&$applied): void {
                    if (! $this->migrator->repositoryExists()) {
                        $this->migrator->getRepository()->createRepository();
                    }

                    $applied = $this->migrator->run($this->modules->tenantMigrationPaths());
                });

                $this->lockDownLedgers($connection, $database->getUsername());
            });

            $latest = $this->migrator->usingConnection(
                self::CONNECTION,
                fn (): ?string => $this->migrator->getRepository()->getLast()[0]->migration ?? null,
            );

            $run->forceFill([
                'status' => 'succeeded',
                'migrations_applied' => count($applied),
                'finished_at' => now(),
            ])->save();

            if (is_string($latest) && $latest !== $tenant->schema_version) {
                $tenant->forceFill(['schema_version' => $latest])->save();
            }
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ])->save();
        } finally {
            $this->db->purge(self::CONNECTION);
        }

        return $run;
    }

    /**
     * Append-only ledgers are enforced by the database, not by code review: the application
     * role keeps INSERT and SELECT only (database-roles.md §3). Re-applied on every run, so a
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
