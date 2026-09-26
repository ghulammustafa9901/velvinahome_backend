<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\DeleteTenant;
use Modules\Platform\Exceptions\TenantLifecycleViolation;
use Modules\Platform\Models\Tenant;

final class TenantDeleteCommand extends Command
{
    protected $signature = 'tenant:delete {slug} {--confirm= : Type the slug again to confirm}';

    protected $description = 'Drop a tenant\'s database and roles (requires confirmation and a backup from the last hour)';

    public function handle(DeleteTenant $delete): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->firstOrFail();

        $confirmation = (string) ($this->option('confirm') ?? $this->ask("Type [{$tenant->slug}] to permanently delete its database"));

        try {
            $delete->handle($tenant, $confirmation);
        } catch (TenantLifecycleViolation $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Tenant [%s] deleted; landlord record archived.', $tenant->slug));

        return self::SUCCESS;
    }
}
