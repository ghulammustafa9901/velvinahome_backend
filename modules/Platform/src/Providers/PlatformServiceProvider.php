<?php

declare(strict_types=1);

namespace Modules\Platform\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Console\CompanyCreateCommand;
use Modules\Platform\Console\LandlordMigrateCommand;
use Modules\Platform\Console\PlatformDemoCommand;
use Modules\Platform\Console\PlatformUserCommand;
use Modules\Platform\Console\SecretsReencryptCommand;
use Modules\Platform\Console\TenantActivateCommand;
use Modules\Platform\Console\TenantCreateCommand;
use Modules\Platform\Console\TenantDeleteCommand;
use Modules\Platform\Console\TenantExportCommand;
use Modules\Platform\Console\TenantRestoreCommand;
use Modules\Platform\Console\TenantRotateDbPasswordsCommand;
use Modules\Platform\Console\TenantsBackupCommand;
use Modules\Platform\Console\TenantsMigrateCommand;
use Modules\Platform\Console\TenantSuspendCommand;
use Modules\Platform\Contracts\FeatureFlags;
use Modules\Platform\Contracts\OnboardingChecks;
use Modules\Platform\Contracts\OwnerBrands;
use Modules\Platform\Contracts\TenantDirectory;
use Modules\Platform\Listeners\AuditPlatformAuthentication;
use Modules\Platform\Models\Domain;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantDatabase;
use Modules\Platform\Support\LandlordTenantDirectory;
use Modules\Platform\Support\LaunchChecklist;
use Modules\Platform\Support\OwnerBrandDirectory;
use Modules\Platform\Support\PlanFeatureFlags;
use Modules\Platform\Tenancy\TenantResolver;
use Modules\Support\Events\StoredSecretsReencrypted;
use Modules\Support\Providers\ModuleServiceProvider;
use Modules\Support\Security\EncryptedColumn;
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
        $this->app->bind(OwnerBrands::class, OwnerBrandDirectory::class);
        $this->app->bind(TenantDirectory::class, LandlordTenantDirectory::class);
        $this->app->singleton(LaunchChecklist::class);
        $this->app->alias(LaunchChecklist::class, OnboardingChecks::class);
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
        Event::listen(Failed::class, [AuditPlatformAuthentication::class, 'handleFailed']);
        Event::listen(Login::class, [AuditPlatformAuthentication::class, 'handleLogin']);

        $this->scopeInjectedCacheRepository();
        $this->forgetCachedResolutions();

        $this->loadRoutesFrom($this->modulePath().'/routes/admin.php');
    }

    /**
     * Host → tenant resolutions are cached (TenantResolver). Status, domains and credentials
     * must take effect at once — a suspended brand answers 503 on its next request.
     */
    private function forgetCachedResolutions(): void
    {
        $resolver = fn (): TenantResolver => $this->app->make(TenantResolver::class);

        Tenant::saved(fn (Tenant $tenant) => $resolver()->forgetTenant($tenant->id));
        Tenant::deleting(fn (Tenant $tenant) => $resolver()->forgetTenant($tenant->id));
        TenantDatabase::saved(fn (TenantDatabase $database) => $resolver()->forgetTenant($database->tenant_id));
        TenantDatabase::deleted(fn (TenantDatabase $database) => $resolver()->forgetTenant($database->tenant_id));

        $domain = function (Domain $domain) use ($resolver): void {
            $resolver()->forget($domain->hostname, $domain->kind);
            $original = $domain->getOriginal('hostname');

            if (is_string($original) && $original !== $domain->hostname) {
                $resolver()->forget($original, $domain->kind);
            }
        };
        Domain::saved($domain);
        Domain::deleted($domain);

        // Re-encrypted database passwords are written without model events.
        Event::listen(StoredSecretsReencrypted::class, function (StoredSecretsReencrypted $event) use ($resolver): void {
            if ($event->scope === EncryptedColumn::CENTRAL) {
                $resolver()->forgetAll();
            }
        });
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
            TenantRestoreCommand::class,
            TenantsBackupCommand::class,
            TenantDeleteCommand::class,
            TenantsMigrateCommand::class,
            PlatformUserCommand::class,
            PlatformDemoCommand::class,
            SecretsReencryptCommand::class,
            TenantRotateDbPasswordsCommand::class,
        ];
    }

    protected function encryptedColumns(): array
    {
        return [
            EncryptedColumn::central('tenant_databases', 'app_password'),
            EncryptedColumn::central('tenant_databases', 'migration_password'),
            EncryptedColumn::central('platform_users', 'two_factor_secret'),
            EncryptedColumn::central('platform_users', 'two_factor_recovery_codes'),
        ];
    }
}
