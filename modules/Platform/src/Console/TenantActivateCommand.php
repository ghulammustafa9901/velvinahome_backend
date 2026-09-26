<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\ChangeTenantStatus;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;

final class TenantActivateCommand extends Command
{
    protected $signature = 'tenant:activate {slug} {--reason=}';

    protected $description = 'Re-activate a suspended tenant';

    public function handle(ChangeTenantStatus $changeStatus): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->firstOrFail();

        $changeStatus->handle($tenant, TenantStatus::Active, $this->option('reason') ?: null);

        $this->components->info(sprintf('Tenant [%s] is active.', $tenant->slug));

        return self::SUCCESS;
    }
}
