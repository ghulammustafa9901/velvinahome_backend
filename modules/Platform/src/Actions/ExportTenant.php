<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Support\Facades\Storage;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantBackup;
use Modules\Platform\Support\AuthorizesPlatformActor;
use Modules\Platform\Support\PlatformAudit;
use Modules\Platform\Tenancy\TenantDatabaseConfig;
use Modules\Support\Contracts\Action;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * `tenant:export` — a full, restorable copy of one brand: a pg_dump of its database plus a
 * manifest of its stored files (spec §4.6). Used for backups before destructive operations
 * and for client exit.
 *
 * The dump runs as the tenant's migration role (it owns every object, so the dump is
 * complete) with the password passed through the environment, never on the command line.
 */
final class ExportTenant implements Action
{
    use AuthorizesPlatformActor;

    public function __construct(private readonly PlatformAudit $audit) {}

    public function handle(Tenant $tenant, ?PlatformUser $actor = null): TenantBackup
    {
        $this->authorizeTenantManagement($actor);

        $backup = TenantBackup::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'export',
            'started_at' => now(),
            'status' => 'running',
        ]);

        $directory = storage_path('app/exports/'.$tenant->slug);
        $stamp = now()->format('Ymd_His');
        $dumpPath = $directory.DIRECTORY_SEPARATOR.$stamp.'.dump';

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException("Cannot create export directory {$directory}.");
            }

            $this->dumpDatabase($tenant, $dumpPath);
            file_put_contents($directory.DIRECTORY_SEPARATOR.$stamp.'.media.json', json_encode(
                $this->mediaManifest($tenant),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));

            $backup->forceFill([
                'status' => 'succeeded',
                'location' => $dumpPath,
                'size_bytes' => filesize($dumpPath) ?: 0,
                'checksum' => hash_file('sha256', $dumpPath) ?: null,
                'finished_at' => now(),
            ])->save();

            $tenant->forceFill(['last_backup_at' => now()])->save();
        } catch (Throwable $exception) {
            $backup->forceFill([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        $this->audit->record('tenant.exported', $actor, $tenant->id, 'tenant_backup', (string) $backup->id);

        return $backup;
    }

    private function dumpDatabase(Tenant $tenant, string $path): void
    {
        /** @var TenantDatabaseConfig $database */
        $database = $tenant->database();
        $connection = $database->migratorConnection();

        $process = new Process(
            [
                (string) config('platform.provisioning.pg_dump_binary'),
                '--format=custom',
                '--no-owner',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--file='.$path,
                (string) $connection['database'],
            ],
            env: ['PGPASSWORD' => (string) $connection['password']],
            timeout: 3600,
        );

        $process->mustRun();
    }

    /**
     * @return array{tenant: string, generated_at: string, files: list<array{disk: string, path: string, size: int}>}
     */
    private function mediaManifest(Tenant $tenant): array
    {
        /** @var list<array{disk: string, path: string, size: int}> $files */
        $files = $tenant->run(function (): array {
            $files = [];

            foreach (['tenant_media', 'tenant_private'] as $disk) {
                foreach (Storage::disk($disk)->allFiles() as $path) {
                    $files[] = ['disk' => $disk, 'path' => $path, 'size' => Storage::disk($disk)->size($path)];
                }
            }

            return $files;
        });

        return [
            'tenant' => $tenant->id,
            'generated_at' => now()->toIso8601String(),
            'files' => $files,
        ];
    }
}
