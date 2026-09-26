<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Tenancy\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the few framework endpoints shared by both admin panels (Livewire's update and upload
 * routes). On the platform host the request stays central; on a tenant admin host tenancy is
 * initialised exactly as for a page load; on any other host it is a 404.
 *
 * Without this, every Livewire round-trip from a tenant admin would run its queries against
 * the landlord database.
 */
final class InitializeTenancyForPanelRequest
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (strtolower($request->getHost()) === strtolower((string) config('platform.host'))) {
            return $next($request);
        }

        $tenant = $this->resolver->resolve($request->getHost(), DomainKind::Admin);

        abort_if($tenant === null, 404);
        abort_unless($tenant->status->isServable(), 503);

        tenancy()->initialize($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
