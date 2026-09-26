<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\MigrateLandlord;

final class LandlordMigrateCommand extends Command
{
    protected $signature = 'landlord:migrate';

    protected $description = 'Run landlord migrations as the landlord migration role and re-apply the audit-table lockdown';

    public function handle(MigrateLandlord $migrate): int
    {
        $applied = $migrate->handle();

        if ($applied === []) {
            $this->components->info('Landlord schema is up to date.');
        }

        foreach ($applied as $migration) {
            $this->components->task($migration);
        }

        return self::SUCCESS;
    }
}
