<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantBackup;
use Modules\Platform\Models\TenantRestore;
use Modules\Platform\Support\AuthorizesPlatformActor;
use Modules\Platform\Support\PlatformAudit;
use Modules\Platform\Support\Provisioning\PostgresProvisioner;
use Modules\Platform\Support\Provisioning\TenantDatabaseNames;
use Modules\Platform\Tenancy\TenantDatabaseConfig;
use Modules\Support\Contracts\Action;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restore one brand from a `tenant:export` dump (spec §9, runbooks/restore-tenant.md).
 *
 *  - **drill** (default, safe at any time): restore into a fresh scratch database, compare every
 *    table's row count with the live database, drop the scratch copy. The brand is untouched.
 *  - **in_place** (disaster recovery): only for a suspended brand; a safety export is taken
 *    first, the dump replaces the database in one transaction, privileges are re-applied and
 *    tenant migrations run (they bring an older dump up to date and re-lock the ledgers).
 *
 * Other brands are never involved: one database, its own roles. Every run is recorded.
 */
final class RestoreTenant implements Action
{
    use AuthorizesPlatformActor;

    private const TARGET = 'tenant_restore_target';

    private const LIVE = 'tenant_restore_live';

    public function __construct(
        private readonly PostgresProvisioner $provisioner,
        private readonly ExportTenant $export,
        private readonly MigrateTenant $migrate,
        private readonly PlatformAudit $audit,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(Tenant $tenant, TenantBackup $backup, string $mode = 'drill', ?PlatformUser $actor = null): TenantRestore
    {
        $this->authorizeTenantManagement($actor);

        if (! in_array($mode, ['drill', 'in_place'], true)) {
            throw new RuntimeException("Unknown restore mode {$mode}.");
        }

        $restore = TenantRestore::query()->create([
            'tenant_id' => $tenant->id,
            'backup_id' => $backup->id,
            'mode' => $mode,
            'status' => 'running',
            'differences' => [],
            'actor_ulid' => $actor?->ulid,
            'started_at' => Carbon::now(),
        ]);
        $started = hrtime(true);

        try {
            $this->assertUsable($tenant, $backup, $mode);
            $mode === 'drill' ? $this->drill($tenant, $backup, $restore) : $this->inPlace($tenant, $backup, $actor, $restore);

            $restore->status = $restore->differences === [] ? 'succeeded' : 'failed';
        } catch (Throwable $e) {
            $restore->status = 'failed';
            $restore->error = mb_substr($e->getMessage(), 0, 4000);
        } finally {
            $restore->forceFill([
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'finished_at' => Carbon::now(),
            ])->save();
        }

        $this->audit->record('tenant.restore_'.$mode, $actor, $tenant->id, 'tenant_restore', (string) $restore->id, ['status' => $restore->status]);

        return $restore;
    }

    private function assertUsable(Tenant $tenant, TenantBackup $backup, string $mode): void
    {
        $file = (string) $backup->location;

        if ($backup->tenant_id !== $tenant->id || $backup->status !== 'succeeded' || ! is_file($file)) {
            throw new RuntimeException('That backup is not a usable export of this brand.');
        }

        if ($backup->checksum !== null && ! hash_equals($backup->checksum, (string) hash_file('sha256', $file))) {
            throw new RuntimeException('The backup file does not match its checksum; it may be damaged.');
        }

        if ($mode === 'in_place' && $tenant->status !== TenantStatus::Suspended) {
            throw new RuntimeException('Suspend the brand before restoring it in place, so no orders are taken meanwhile.');
        }
    }

    private function drill(Tenant $tenant, TenantBackup $backup, TenantRestore $restore): void
    {
        $names = TenantDatabaseNames::forSlug($tenant->slug);
        $scratch = mb_substr($names->database, 0, 40).'_drill_'.Carbon::now()->format('YmdHis');
        $live = $this->database($tenant)->migratorConnection();

        $this->provisioner->createScratchDatabase($names, $scratch);

        try {
            $this->pgRestore($live, $scratch, (string) $backup->location, clean: false);
            $this->compare($restore, $this->connect(self::LIVE, $live, $live['database']), $this->connect(self::TARGET, $live, $scratch));
        } finally {
            $this->db->purge(self::LIVE);
            $this->db->purge(self::TARGET);
            $this->provisioner->dropScratchDatabase($names, $scratch);
        }
    }

    private function inPlace(Tenant $tenant, TenantBackup $backup, ?PlatformUser $actor, TenantRestore $restore): void
    {
        // A way back if the restore itself turns out wrong.
        $this->export->handle($tenant, $actor);

        $database = $this->database($tenant);
        $live = $database->migratorConnection();
        $this->pgRestore($live, (string) $live['database'], (string) $backup->location, clean: true);

        $this->provisioner->reapplyPrivileges(TenantDatabaseNames::forSlug($tenant->slug), (string) $live['password'], [
            'host' => (string) $live['host'],
            'port' => (int) $live['port'],
        ]);

        $run = $this->migrate->handle($tenant);

        if ($run->status !== 'succeeded') {
            throw new RuntimeException('Restored, but tenant migrations failed: '.$run->error);
        }

        $connection = $this->connect(self::LIVE, $live, (string) $live['database']);
        $restore->tables_checked = count($this->tables($connection));
        $this->db->purge(self::LIVE);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function pgRestore(array $connection, string $database, string $file, bool $clean): void
    {
        $process = new Process(
            array_values(array_filter([
                (string) config('platform.provisioning.pg_restore_binary'),
                '--no-owner',
                '--no-acl',
                '--exit-on-error',
                '--single-transaction',
                $clean ? '--clean' : null,
                $clean ? '--if-exists' : null,
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--dbname='.$database,
                $file,
            ])),
            env: ['PGPASSWORD' => (string) $connection['password']],
            timeout: 3600,
        );

        $process->mustRun();
    }

    private function compare(TenantRestore $restore, Connection $live, Connection $restored): void
    {
        $liveTables = $this->tables($live);
        $restoredTables = $this->tables($restored);
        $differences = [];
        $rows = 0;

        foreach (array_diff($liveTables, $restoredTables) as $missing) {
            $differences[] = "Table {$missing} is missing from the backup.";
        }

        foreach (array_intersect($liveTables, $restoredTables) as $table) {
            $quoted = '"'.str_replace('"', '""', $table).'"';
            $expected = (int) $live->selectOne("select count(*) as n from {$quoted}")->n;
            $actual = (int) $restored->selectOne("select count(*) as n from {$quoted}")->n;
            $rows += $actual;

            if ($expected !== $actual) {
                $differences[] = "{$table}: {$actual} rows restored, {$expected} live.";
            }
        }

        $restore->forceFill([
            'tables_checked' => count($restoredTables),
            'rows_checked' => $rows,
            'differences' => array_slice($differences, 0, 200),
        ]);
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $connection): array
    {
        return array_values(array_map('strval', $connection->table('pg_tables')->where('schemaname', 'public')->orderBy('tablename')->pluck('tablename')->all()));
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function connect(string $name, array $base, string $database): Connection
    {
        config(['database.connections.'.$name => array_merge($base, ['database' => $database])]);
        $this->db->purge($name);

        return $this->db->connection($name);
    }

    private function database(Tenant $tenant): TenantDatabaseConfig
    {
        /** @var TenantDatabaseConfig $database */
        $database = $tenant->database();

        return $database;
    }
}
