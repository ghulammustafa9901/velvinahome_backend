<?php

declare(strict_types=1);

namespace Modules\Support\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Seeder;
use Modules\Support\Contracts\Action;
use Modules\Support\Modules\ModuleRegistry;

/**
 * Applies every module's default seeders under the application role. Seeders are idempotent
 * and never overwrite a value the store has changed, so this runs after every migration run
 * and new defaults reach existing stores.
 */
final class SeedDefaults implements Action
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Container $container,
    ) {}

    public function handle(): void
    {
        foreach ($this->modules->defaultSeeders() as $seederClass) {
            /** @var Seeder $seeder */
            $seeder = $this->container->make($seederClass);
            $seeder->setContainer($this->container)->__invoke();
        }
    }
}
