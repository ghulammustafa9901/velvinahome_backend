<?php

declare(strict_types=1);

namespace Modules\Support\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Support\Contracts\Action;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The restore drill (spec §9, docs/runbooks/restore-database.md): proves a dump can be
 * restored, by restoring it into the scratch database `<database>_restore_drill` and
 * comparing every table's row count with the live database. The scratch database is created
 * once by database/setup/bootstrap-database.sql; the store roles cannot create databases.
 *
 * A dump whose checksum no longer matches its manifest is refused before anything is restored.
 */
final class RestoreDrill implements Action
{
    private const CONNECTION = 'restore_drill';

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<string> differences between the live database and the restored copy
     */
    public function handle(string $dump): array
    {
        $this->verifyChecksum($dump);

        $live = config('database.connections.pgsql_migrator');
        $scratch = [...$live, 'database' => (string) config('store.backup.drill_database')];
        config(['database.connections.'.self::CONNECTION => $scratch]);
        $this->db->purge(self::CONNECTION);

        try {
            // The migration role owns the scratch database, so it may empty it.
            $drill = $this->db->connection(self::CONNECTION);
            $drill->statement('DROP SCHEMA IF EXISTS public CASCADE');
            $drill->statement('CREATE SCHEMA public');
            $this->db->purge(self::CONNECTION);

            (new Process(
                [
                    (string) config('store.backup.pg_restore_binary'),
                    '--no-owner',
                    '--no-privileges',
                    '--exit-on-error',
                    '--host='.$scratch['host'],
                    '--port='.$scratch['port'],
                    '--username='.$scratch['username'],
                    '--dbname='.$scratch['database'],
                    $dump,
                ],
                env: ['PGPASSWORD' => (string) $scratch['password']],
                timeout: 3600,
            ))->mustRun();

            return $this->compare($this->counts('pgsql_migrator'), $this->counts(self::CONNECTION));
        } finally {
            $this->db->purge(self::CONNECTION);
            config(['database.connections.'.self::CONNECTION => null]);
        }
    }

    private function verifyChecksum(string $dump): void
    {
        $manifest = BackupDatabase::manifestPath($dump);

        if (! is_file($dump) || ! is_file($manifest)) {
            throw new RuntimeException("The dump or its manifest is missing: {$dump}");
        }

        $expected = (string) (json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['sha256'] ?? '');

        if ($expected === '' || ! hash_equals($expected, (string) hash_file('sha256', $dump))) {
            throw new RuntimeException("The dump no longer matches its checksum and is not restored: {$dump}");
        }
    }

    /**
     * @return array<string, int> table => rows
     */
    private function counts(string $connection): array
    {
        $db = $this->db->connection($connection);
        $counts = [];

        foreach ($db->table('pg_tables')->where('schemaname', 'public')->orderBy('tablename')->pluck('tablename') as $table) {
            $counts[(string) $table] = (int) $db->table((string) $table)->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $live
     * @param  array<string, int>  $restored
     * @return list<string>
     */
    private function compare(array $live, array $restored): array
    {
        $differences = [];

        foreach (array_unique([...array_keys($live), ...array_keys($restored)]) as $table) {
            if (($live[$table] ?? null) !== ($restored[$table] ?? null)) {
                $differences[] = sprintf('%s: live %s, restored %s', $table, $live[$table] ?? 'missing', $restored[$table] ?? 'missing');
            }
        }

        return $differences;
    }
}
