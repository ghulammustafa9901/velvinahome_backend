<?php

declare(strict_types=1);

namespace Modules\Support\Console;

use Illuminate\Console\Command;
use Modules\Support\Actions\MigrateDatabase;
use Modules\Support\Actions\SeedDefaults;
use Throwable;

/**
 * Part of every deploy: all pending migrations in one transaction as the migration role, then
 * the module defaults. Exits non-zero on failure, which blocks the release.
 */
final class MigrateDatabaseCommand extends Command
{
    protected $signature = 'app:migrate {--no-seed : Skip the module default seeders}';

    protected $description = 'Run pending migrations in one transaction (as the migration role), then apply module defaults';

    public function handle(MigrateDatabase $migrate, SeedDefaults $seed): int
    {
        try {
            $applied = $migrate->handle();
        } catch (Throwable $exception) {
            $this->components->error('Migrations failed and were rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($applied as $migration) {
            $this->components->twoColumnDetail($migration, '<fg=green>applied</>');
        }

        $this->components->info($applied === [] ? 'Nothing to migrate.' : count($applied).' migration(s) applied.');

        if (! $this->option('no-seed')) {
            $seed->handle();
            $this->components->info('Module defaults applied.');
        }

        return self::SUCCESS;
    }
}
