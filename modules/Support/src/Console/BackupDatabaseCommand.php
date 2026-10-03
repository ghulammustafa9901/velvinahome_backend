<?php

declare(strict_types=1);

namespace Modules\Support\Console;

use Illuminate\Console\Command;
use Modules\Support\Actions\BackupDatabase;
use Modules\Support\Actions\RestoreDrill;
use Throwable;

/**
 * Nightly backup, and on Sundays the restore drill (routes/console.php). Exits non-zero when
 * the dump fails or the restored copy differs from the live database.
 */
final class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup {--drill : Also restore the new dump into the scratch database and compare it with the live one}';

    protected $description = 'Dump the database (with a checksum manifest), optionally proving it restores';

    public function handle(BackupDatabase $backup, RestoreDrill $drill): int
    {
        try {
            $dump = $backup->handle();
        } catch (Throwable $exception) {
            $this->components->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Backup written: {$dump}");

        if (! $this->option('drill')) {
            return self::SUCCESS;
        }

        try {
            $differences = $drill->handle($dump);
        } catch (Throwable $exception) {
            $this->components->error('Restore drill failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($differences !== []) {
            $this->components->error('The restored copy differs from the live database:');
            foreach ($differences as $difference) {
                $this->line('  '.$difference);
            }

            return self::FAILURE;
        }

        $this->components->info('Restore drill passed: the restored copy matches the live database.');

        return self::SUCCESS;
    }
}
