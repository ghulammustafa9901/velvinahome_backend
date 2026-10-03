<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Modules\Identity\Contracts\CustomerImport;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Identity\Listeners\AuditStaffAuthentication;
use Modules\Identity\Seeders\StaffRolesSeeder;
use Modules\Identity\Support\ActionCustomerImport;
use Modules\Identity\Support\EloquentStaffDirectory;
use Modules\Support\Providers\ModuleServiceProvider;
use Modules\Support\Security\EncryptedColumn;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    protected function registerModule(): void
    {
        $this->app->bind(StaffDirectory::class, EloquentStaffDirectory::class);
        $this->app->bind(CustomerImport::class, ActionCustomerImport::class);
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath().'/resources/views', 'identity');

        Event::listen(Attempting::class, [AuditStaffAuthentication::class, 'handleAttempting']);
        Event::listen(Failed::class, [AuditStaffAuthentication::class, 'handleFailed']);
        Event::listen(Login::class, [AuditStaffAuthentication::class, 'handleLogin']);
    }

    protected function appendOnlyTables(): array
    {
        return ['login_audits', 'activity_log'];
    }

    protected function defaultSeeders(): array
    {
        return [StaffRolesSeeder::class];
    }

    protected function encryptedColumns(): array
    {
        return [
            EncryptedColumn::make('users', 'two_factor_secret'),
            EncryptedColumn::make('users', 'two_factor_recovery_codes'),
        ];
    }
}
