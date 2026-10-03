<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\RotateTenantDatabasePasswords;
use Modules\Platform\Models\Tenant;

final class TenantRotateDbPasswordsCommand extends Command
{
    protected $signature = 'tenant:rotate-db-passwords {slug}';

    protected $description = 'Give a brand\'s two database roles new passwords (landlord record updated together)';

    public function handle(RotateTenantDatabasePasswords $rotate): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->firstOrFail();

        $rotate->handle($tenant);

        $this->components->info(sprintf('Database passwords of [%s] rotated.', $tenant->slug));

        return self::SUCCESS;
    }
}
