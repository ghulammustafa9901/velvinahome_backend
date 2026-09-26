<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\ChangeTenantStatus;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;

final class TenantSuspendCommand extends Command
{
    protected $signature = 'tenant:suspend {slug} {--reason=}';

    protected $description = 'Suspend a tenant (storefront, API and admin answer 503)';

    public function handle(ChangeTenantStatus $changeStatus): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->firstOrFail();

        $changeStatus->handle($tenant, TenantStatus::Suspended, $this->option('reason') ?: null);

        $this->components->info(sprintf('Tenant [%s] is suspended.', $tenant->slug));

        return self::SUCCESS;
    }
}
