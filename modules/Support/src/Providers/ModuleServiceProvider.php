<?php

declare(strict_types=1);

namespace Modules\Support\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use RuntimeException;

/**
 * Base provider every module extends, so wiring a module is identical across all of them
 * (ADR-0007).
 *
 * Deliberately does NOT load migrations into the default migrator. Landlord and tenant
 * migrations are separate (spec §4.7) and are registered with the tenancy layer in Phase 1
 * Milestone 1.2. Auto-loading them here would be the quiet beginning of tenant tables
 * appearing in the landlord database.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * The module's short name, e.g. "Catalog".
     */
    abstract protected function moduleName(): string;

    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->bootModule();
    }

    public function register(): void
    {
        $this->registerModule();
    }

    /**
     * Module-specific container bindings. Bind contracts to implementations here; a module's
     * contracts are the only surface other modules may depend on.
     */
    protected function registerModule(): void {}

    /**
     * Module-specific boot work that is not routes, policies or listeners.
     */
    protected function bootModule(): void {}

    /**
     * Policies this module owns, as `Model::class => Policy::class`.
     *
     * Policies are registered here but enforced inside Actions (spec §6.2), so the API, the
     * admin panel and a console command are all held to the same rule.
     *
     * @return array<class-string, class-string>
     */
    protected function policies(): array
    {
        return [];
    }

    /**
     * Domain event listeners, as `Event::class => [Listener::class, ...]`.
     *
     * Cross-module consequences arrive here: a module reacts to another module's published
     * event rather than being called by it (spec §3.4).
     *
     * @return array<class-string, list<class-string>>
     */
    protected function listeners(): array
    {
        return [];
    }

    /**
     * Absolute path to the module's landlord migrations, if it has any. Only the Platform
     * module should return a path here.
     */
    public function landlordMigrationPath(): ?string
    {
        $path = $this->modulePath().'/database/migrations/landlord';

        return is_dir($path) ? $path : null;
    }

    /**
     * Absolute path to the module's tenant migrations, if it has any.
     */
    public function tenantMigrationPath(): ?string
    {
        $path = $this->modulePath().'/database/migrations/tenant';

        return is_dir($path) ? $path : null;
    }

    protected function registerApiRoutes(): void
    {
        $routes = $this->modulePath().'/routes/api_v1.php';

        if (! is_file($routes)) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group($routes);
    }

    protected function registerPolicies(): void
    {
        foreach ($this->policies() as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    protected function registerEventListeners(): void
    {
        foreach ($this->listeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    /**
     * The module's root directory, derived from the concrete provider's own file location so
     * no module has to hardcode its path.
     */
    protected function modulePath(): string
    {
        $providerFile = (new ReflectionClass($this))->getFileName();

        if ($providerFile === false) {
            throw new RuntimeException(sprintf(
                'Unable to determine the path of module [%s].',
                $this->moduleName(),
            ));
        }

        // .../modules/<Module>/src/Providers/<Module>ServiceProvider.php -> .../modules/<Module>
        return dirname($providerFile, 3);
    }
}
