<?php

declare(strict_types=1);

namespace Modules\Settings\Providers;

use Modules\Integrations\Contracts\StorefrontCache;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Settings\Models\Setting;
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

    protected function bootModule(): void
    {
        // Theme, store name, flags… are part of every page: purge the brand's config tag.
        Setting::saved(fn () => $this->app->make(StorefrontCache::class)->invalidate(['config']));
    }

    protected function tenantSeeders(): array
    {
        return [DefaultSettingsSeeder::class];
    }
}
