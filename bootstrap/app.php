<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Platform\Http\Middleware\EnsureFeatureEnabled;
use Modules\Platform\Http\Middleware\EnsurePlatformHost;
use Modules\Platform\Http\Middleware\InitializeTenancyByHost;
use Modules\Platform\Http\Middleware\InitializeTenancyForPanelRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => InitializeTenancyByHost::class,
            'platform.host' => EnsurePlatformHost::class,
            'feature' => EnsureFeatureEnabled::class,
        ]);

        // Tenancy must be initialised before sessions, authentication and route-model
        // binding run, or they would all read the landlord database.
        $middleware->prependToPriorityList(
            before: EncryptCookies::class,
            prepend: InitializeTenancyByHost::class,
        );
        $middleware->prependToPriorityList(
            before: EncryptCookies::class,
            prepend: InitializeTenancyForPanelRequest::class,
        );

        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
