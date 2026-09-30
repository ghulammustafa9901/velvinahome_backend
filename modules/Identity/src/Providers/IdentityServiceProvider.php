<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Identity\Listeners\AuditStaffAuthentication;
use Modules\Identity\Seeders\StaffRolesSeeder;
use Modules\Identity\Support\EloquentStaffDirectory;
use Modules\Support\Providers\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    protected function registerModule(): void
    {
        $this->app->bind(StaffDirectory::class, EloquentStaffDirectory::class);
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

    protected function tenantSeeders(): array
    {
        return [StaffRolesSeeder::class];
    }
}
