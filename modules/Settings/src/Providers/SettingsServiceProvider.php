<?php

declare(strict_types=1);

namespace Modules\Settings\Providers;

use Modules\Settings\Contracts\TenantSettings;
use Modules\Settings\Seeders\DefaultSettingsSeeder;
use Modules\Settings\Support\DatabaseTenantSettings;
use Modules\Support\Providers\ModuleServiceProvider;

final class SettingsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Settings';
    }

    protected function registerModule(): void
    {
        $this->app->bind(TenantSettings::class, DatabaseTenantSettings::class);
    }

    protected function tenantSeeders(): array
    {
        return [DefaultSettingsSeeder::class];
    }
}
