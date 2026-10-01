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
use Modules\Support\Http\ApiCachePolicy;
use Modules\Support\Http\ApiErrorRenderer;
use Modules\Support\Http\AssignRequestId;
use Modules\Support\Http\SecurityHeaders;
use Modules\Support\Http\TrustStorefrontService;
use Modules\Support\Monitoring\PostHogErrors;

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

        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

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
        // A flag-gated endpoint answers 403 feature_disabled before asking anyone to sign in.
        $middleware->prependToPriorityList(
            before: EncryptCookies::class,
            prepend: EnsureFeatureEnabled::class,
        );
        // Outermost on API routes, so every response — including 401s and 429s produced by
        // later middleware — gets the cache policy.
        $middleware->prependToPriorityList(
            before: InitializeTenancyByHost::class,
            prepend: ApiCachePolicy::class,
        );
        // Before any rate limiter reads the client IP.
        $middleware->prependToPriorityList(
            before: InitializeTenancyByHost::class,
            prepend: TrustStorefrontService::class,
        );

        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Storefront API errors use one envelope everywhere (api-conventions §3).
        $exceptions->render(fn (Throwable $exception, Request $request) => (new ApiErrorRenderer)($exception, $request));

        // Unhandled errors also go to PostHog error tracking (scrubbed; ADR-0019).
        $exceptions->report(function (Throwable $exception): void {
            app(PostHogErrors::class)->report($exception);
        });

        // Expected domain outcomes are not incidents.
        $exceptions->dontReport([
            Modules\Inventory\Exceptions\InsufficientStock::class,
        ]);
    })->create();
