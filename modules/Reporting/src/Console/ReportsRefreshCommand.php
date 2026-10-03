<?php

declare(strict_types=1);

namespace Modules\Reporting\Console;

use Illuminate\Console\Command;
use Modules\Reporting\Actions\RefreshReports;

final class ReportsRefreshCommand extends Command
{
    protected $signature = 'reports:refresh {--full : Rebuild every day instead of only the changed ones}';

    protected $description = 'Bring the report aggregates up to date from orders and refunds';

    public function handle(RefreshReports $refresh): int
    {
        $days = $refresh->handle((bool) $this->option('full'));
        $this->components->info(count($days).' days rebuilt.');

        return self::SUCCESS;
    }
}
