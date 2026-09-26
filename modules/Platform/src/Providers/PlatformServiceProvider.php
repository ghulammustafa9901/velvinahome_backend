<?php

declare(strict_types=1);

namespace Modules\Platform\Providers;

use Illuminate\Support\Facades\Event;
use Modules\Platform\Console\CompanyCreateCommand;
use Modules\Platform\Console\LandlordMigrateCommand;
use Modules\Platform\Console\PlatformDemoCommand;
use Modules\Platform\Console\PlatformUserCommand;
use Modules\Platform\Console\TenantActivateCommand;
use Modules\Platform\Console\TenantCreateCommand;
use Modules\Platform\Console\TenantDeleteCommand;
use Modules\Platform\Console\TenantExportCommand;
use Modules\Platform\Console\TenantsMigrateCommand;
use Modules\Platform\Console\TenantSuspendCommand;
use Modules\Platform\Contracts\FeatureFlags;
use Modules\Platform\Support\PlanFeatureFlags;
use Modules\Platform\Tenancy\TenantResolver;
use Modules\Support\Providers\ModuleServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

final class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Platform';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(TenantResolver::class);
        $this->app->scoped(FeatureFlags::class, PlanFeatureFlags::class);
    }

    /**
     * Laravel binds the default cache repository as a singleton (from a deferred provider, so
     * it can only be overridden once that provider has loaded). Resolved once before tenancy
     * starts, every class injecting Cache\Repository would keep writing untagged,
     * cross-tenant keys. Re-resolving through the tenant tag on every injection closes that
     * channel (spec §4.4, isolation channel 4).
     */
    private function scopeInjectedCacheRepository(): void
    {
        $this->app->make('cache');

        $this->app->bind('cache.store', function ($app) {
            $store = $app['cache']->driver();

            return tenancy()->initialized
                ? $store->tags([config('tenancy.cache.tag_base').tenant()->getTenantKey()])
                : $store;
        });
    }

    /**
     * stancl/tenancy runs its bootstrappers from these two events. The package leaves the
     * wiring to an application stub; it lives here because tenancy is this module's concern.
     */
    protected function bootModule(): void
    {
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        $this->scopeInjectedCacheRepository();
    }

    protected function consoleCommands(): array
    {
        return [
            LandlordMigrateCommand::class,
            CompanyCreateCommand::class,
            TenantCreateCommand::class,
            TenantSuspendCommand::class,
            TenantActivateCommand::class,
            TenantExportCommand::class,
            TenantDeleteCommand::class,
            TenantsMigrateCommand::class,
            PlatformUserCommand::class,
            PlatformDemoCommand::class,
        ];
    }
}
