<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Enums\DomainKind;
use Modules\Platform\Tenancy\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tenant:{kind}` — the first middleware on every tenant route.
 *
 * Unknown host, or a known host of another kind: 404, before sessions, auth or route
 * bindings run. A suspended or still-provisioning tenant: 503. Tenancy is ended when the
 * request terminates, so a long-lived worker never carries one tenant into the next request.
 */
final class InitializeTenancyByHost
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $tenant = $this->resolver->resolve($request->getHost(), DomainKind::from($kind));

        abort_if($tenant === null, 404);
        abort_unless($tenant->status->isServable(), 503, 'This store is temporarily unavailable.');

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
