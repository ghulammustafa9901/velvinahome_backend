<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a session to the tenant (or to the platform) that created it.
 *
 * Session cookies are already host-scoped, but a cookie replayed onto another brand's admin
 * host would otherwise load a session whose staff id means a different person in that
 * brand's database. Refusing the mismatch closes that channel regardless of cookie handling.
 */
final class ScopeSessionToTenant
{
    public const KEY = '_tenant_scope';

    public function handle(Request $request, Closure $next): Response
    {
        $scope = tenancy()->initialized ? 'tenant:'.tenant()?->getTenantKey() : 'platform';
        $session = $request->session();

        if (! $session->has(self::KEY)) {
            $session->put(self::KEY, $scope);
        } elseif ($session->get(self::KEY) !== $scope) {
            $session->invalidate();
            abort(403);
        }

        return $next($request);
    }
}
