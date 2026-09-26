<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Models\ImpersonationSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support access is time-limited (spec §4.9). Once the impersonation window closes the
 * admin session is ended and the landlord record is closed — the platform user has to
 * start a new, newly justified session.
 */
final class EnforceImpersonationExpiry
{
    public const SESSION_KEY = 'platform_impersonation';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var array{session: string, expires_at: int|null}|null $impersonation */
        $impersonation = $request->session()->get(self::SESSION_KEY);

        if ($impersonation !== null && ($impersonation['expires_at'] ?? 0) <= now()->getTimestamp()) {
            ImpersonationSession::query()
                ->where('ulid', $impersonation['session'])
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);

            auth('staff')->logout();
            $request->session()->invalidate();

            return redirect('/login');
        }

        return $next($request);
    }
}
