<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Settings\Http\Middleware\EnsureFeatureEnabled;
use Modules\Support\Http\ApiCachePolicy;
use Modules\Support\Http\ApiErrorRenderer;
use Modules\Support\Http\AssignRequestId;
use Modules\Support\Http\SecurityHeaders;
use Modules\Support\Http\TrustStorefrontService;
use Modules\Support\Http\VerifyHumanChallenge;
use Modules\Support\Monitoring\PostHogErrors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
            'human' => VerifyHumanChallenge::class,
        ]);

        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // A flag-gated endpoint answers 403 feature_disabled before asking anyone to sign in.
        $middleware->prependToPriorityList(
            before: EncryptCookies::class,
            prepend: EnsureFeatureEnabled::class,
        );
        // Outermost on API routes, so every response — including 401s and 429s produced by
        // later middleware — gets the cache policy.
        $middleware->prependToPriorityList(
            before: EnsureFeatureEnabled::class,
            prepend: ApiCachePolicy::class,
        );
        // Before any rate limiter reads the client IP.
        $middleware->prependToPriorityList(
            before: EnsureFeatureEnabled::class,
            prepend: TrustStorefrontService::class,
        );

        // Staff tooling outside the panel (Horizon, API docs) sends guests to the admin login.
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));

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
