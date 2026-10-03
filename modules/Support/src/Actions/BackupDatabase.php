<?php

declare(strict_types=1);

namespace Modules\Support\Actions;

use Illuminate\Support\Facades\Storage;
use Modules\Support\Contracts\Action;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `db:backup` — a complete, restorable copy of the store: a pg_dump of the database (custom
 * format) and, beside it, a manifest with the dump's SHA-256 and the list of stored files
 * (spec §9). Older dumps beyond BACKUP_RETENTION are pruned.
 *
 * The dump runs as the migration role, which owns every object, so the dump is complete. The
 * password goes through the environment, never on the command line.
 */
final class BackupDatabase implements Action
{
    /**
     * @return string absolute path of the new dump
     */
    public function handle(): string
    {
        $connection = config('database.connections.pgsql_migrator');
        $directory = rtrim((string) config('store.backup.path'), '/\\');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the backup directory {$directory}.");
        }

        $dump = $directory.DIRECTORY_SEPARATOR.$connection['database'].'_'.now()->format('Ymd_His_u').'.dump';

        (new Process(
            [
                (string) config('store.backup.pg_dump_binary'),
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--file='.$dump,
                (string) $connection['database'],
            ],
            env: ['PGPASSWORD' => (string) $connection['password']],
            timeout: 3600,
        ))->mustRun();

        file_put_contents(self::manifestPath($dump), json_encode([
            'database' => $connection['database'],
            'created_at' => now()->toIso8601String(),
            'size_bytes' => filesize($dump) ?: 0,
            'sha256' => hash_file('sha256', $dump),
            'files' => $this->files(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->prune($directory);

        return $dump;
    }

    public static function manifestPath(string $dump): string
    {
        return preg_replace('/\.dump$/', '.json', $dump) ?? $dump.'.json';
    }

    /**
     * @return list<array{disk: string, path: string, size: int}>
     */
    private function files(): array
    {
        $files = [];

        foreach (['media', 'private'] as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $path) {
                $files[] = ['disk' => $disk, 'path' => $path, 'size' => Storage::disk($disk)->size($path)];
            }
        }

        return $files;
    }

    private function prune(string $directory): void
    {
        $dumps = glob($directory.DIRECTORY_SEPARATOR.'*.dump') ?: [];
        sort($dumps);

        foreach (array_slice($dumps, 0, max(0, count($dumps) - max(1, (int) config('store.backup.retention', 14)))) as $old) {
            @unlink($old);
            @unlink(self::manifestPath($old));
        }
    }
}
