<?php

declare(strict_types=1);

namespace Modules\Support\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Support\Http\ApiCachePolicy;
use Modules\Support\Http\TrustStorefrontService;
use Modules\Support\Modules\ModuleRegistry;
use Modules\Support\Security\EncryptedColumn;
use ReflectionClass;
use RuntimeException;

/**
 * Base provider every module extends, so wiring a module is identical across all of them
 * (ADR-0007): its migrations, routes, policies, listeners, commands, default seeders,
 * append-only tables and encrypted columns.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * The module's short name, e.g. "Catalog".
     */
    abstract protected function moduleName(): string;

    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->registerApiRoutes();
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->registerConsoleCommands();
        $this->bootModule();
    }

    public function register(): void
    {
        $this->app->singletonIf(ModuleRegistry::class);

        $this->app->make(ModuleRegistry::class)->register(
            $this->moduleName(),
            $this->appendOnlyTables(),
            $this->defaultSeeders(),
            $this->encryptedColumns(),
        );

        $this->registerModule();
    }

    /**
     * Tables this module owns that are append-only ledgers. The application role loses
     * UPDATE, DELETE and TRUNCATE on them after every migration run (spec §6.3).
     *
     * @return list<string>
     */
    protected function appendOnlyTables(): array
    {
        return [];
    }

    /**
     * Columns this module stores APP_KEY ciphertext in (encrypted casts, Crypt calls), so a key
     * rotation re-encrypts them (`secrets:reencrypt`). An architecture test checks that every
     * encrypted cast is declared.
     *
     * @return list<EncryptedColumn>
     */
    protected function encryptedColumns(): array
    {
        return [];
    }

    /**
     * Idempotent seeders that give a new store this module's defaults. They run after every
     * migration run too, so new defaults reach existing stores.
     *
     * @return list<class-string>
     */
    protected function defaultSeeders(): array
    {
        return [];
    }

    /**
     * Artisan commands this module provides.
     *
     * @return list<class-string>
     */
    protected function consoleCommands(): array
    {
        return [];
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
     * The module's migrations join the application's migrator, so `app:migrate` (and the
     * framework's own migrate command) see every module.
     */
    protected function loadModuleMigrations(): void
    {
        $path = $this->modulePath().'/database/migrations';

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    /**
     * Storefront API routes, under /api/v1 with the API middleware stack.
     */
    protected function registerApiRoutes(): void
    {
        $routes = $this->modulePath().'/routes/api_v1.php';

        if (! is_file($routes)) {
            return;
        }

        Route::middleware(['api', ApiCachePolicy::class, TrustStorefrontService::class])
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group($routes);
    }

    /**
     * Commands are registered once the whole application has booted, so a module command
     * deliberately named like a package command is the one Artisan keeps.
     */
    protected function registerConsoleCommands(): void
    {
        $commands = $this->consoleCommands();

        if ($commands === [] || ! $this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function () use ($commands): void {
            Artisan::starting(function ($artisan) use ($commands): void {
                $artisan->resolveCommands($commands);
            });
        });
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
