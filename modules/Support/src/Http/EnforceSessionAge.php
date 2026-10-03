<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel sessions end after `store.panels.max_session_hours`, however active they are
 * (ASVS 3.3.2); idle sessions already end after SESSION_LIFETIME minutes. Logging out also
 * cycles the remember-me token, so the cookie cannot quietly start a new session.
 *
 * Usage: EnforceSessionAge::class.':staff'
 */
final class EnforceSessionAge
{
    public const SESSION_KEY = 'auth_started_at';

    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $session = $request->session();

        if (! auth($guard)->check()) {
            $session->forget(self::SESSION_KEY);

            return $next($request);
        }

        $started = $session->get(self::SESSION_KEY);

        if (! is_int($started)) {
            $session->put(self::SESSION_KEY, now()->getTimestamp());

            return $next($request);
        }

        $maxSeconds = (int) (config('store.panels.max_session_hours', 12) * 3600);

        if (now()->getTimestamp() - $started >= $maxSeconds) {
            auth($guard)->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect('/login');
        }

        return $next($request);
    }
}
