<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Livewire\Livewire;
use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Http\Middleware\InitializeTenancyForPanelRequest;
use Modules\Platform\Http\Middleware\ScopeSessionToTenant;
use Modules\Platform\Models\PlatformUser;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Lazy loading, silently discarded attributes and missing attributes are bugs; make
        // them loud everywhere except production.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
        $this->configureLivewireEndpoints();

        // Horizon is operations tooling: platform engineers and owners only, on the platform
        // host only (see config/horizon.php).
        Gate::define('viewHorizon', fn ($user = null): bool => $user instanceof PlatformUser
            && $user->status === 'active'
            && $user->role !== PlatformRole::Support);
        Horizon::auth(fn (Request $request): bool => Gate::forUser($request->user('platform'))->allows('viewHorizon'));
    }

    /**
     * Every limiter key includes the tenant, so one brand can never exhaust another brand's
     * budget (spec §4.4, §6.1).
     */
    private function configureRateLimiting(): void
    {
        $tenantKey = static fn (): string => tenancy()->initialized ? (string) tenant()?->getTenantKey() : 'central';

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($tenantKey().'|'.($request->user('customer')?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($tenantKey().'|'.$request->ip()));

        RateLimiter::for('catalog', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($tenantKey().'|'.$request->ip()));

        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($tenantKey().'|'.$request->ip()));
    }

    /**
     * Livewire's update endpoint is shared by both panels. It must initialise tenancy from the
     * host exactly like a panel page does, or tenant-admin components would query the
     * landlord database.
     */
    private function configureLivewireEndpoints(): void
    {
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)
            ->middleware(['web', InitializeTenancyForPanelRequest::class, ScopeSessionToTenant::class]));
    }
}
