<?php

declare(strict_types=1);

namespace Modules\Reporting\Console;

use Illuminate\Console\Command;
use Modules\Platform\Contracts\TenantDirectory;
use Modules\Reporting\Actions\RefreshReports;

final class ReportsRefreshCommand extends Command
{
    protected $signature = 'reports:refresh
        {--tenant=* : Tenant ids (default: every active tenant)}
        {--full : Rebuild every day instead of only the changed ones}';

    protected $description = 'Bring the report aggregates up to date from orders and refunds';

    public function handle(TenantDirectory $tenants): int
    {
        /** @var list<string> $only */
        $only = $this->option('tenant');

        foreach ($only === [] ? $tenants->activeTenantIds() : $only as $tenantId) {
            $days = $tenants->runIn($tenantId, fn (): array => app(RefreshReports::class)->handle((bool) $this->option('full')));
            $this->components->twoColumnDetail($tenantId, count($days).' days rebuilt');
        }

        return self::SUCCESS;
    }
}
