<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The landlord panel exists only on the platform host. Anywhere else it is a 404 — not a
 * 403 — so a tenant host does not even reveal that a platform panel exists (spec §4.9).
 */
final class EnsurePlatformHost
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(strtolower($request->getHost()) === strtolower((string) config('platform.host')), 404);
        abort_if(tenancy()->initialized, 404);

        return $next($request);
    }
}
