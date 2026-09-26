<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Seeder;
use Modules\Platform\Models\Tenant;
use Modules\Support\Contracts\Action;
use Modules\Support\Modules\ModuleRegistry;

/**
 * Applies every module's default seeders inside the tenant's own context, under its
 * application role. Seeders are idempotent, so this is safe to repeat — provisioning does
 * exactly that after a partial failure.
 */
final class SeedTenant implements Action
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Container $container,
    ) {}

    public function handle(Tenant $tenant): void
    {
        $tenant->run(function (): void {
            foreach ($this->modules->tenantSeeders() as $seederClass) {
                /** @var Seeder $seeder */
                $seeder = $this->container->make($seederClass);
                $seeder->setContainer($this->container)->__invoke();
            }
        });
    }
}
