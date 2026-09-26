<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Platform\Actions\ConsumeHandoff;
use Modules\Platform\Http\Middleware\EnforceImpersonationExpiry;
use Modules\Platform\Http\Middleware\ScopeSessionToTenant;
use Modules\Platform\Models\Tenant;

/**
 * GET /_handoff/{kind}/{token} on a tenant admin host. Thin: redeem, then start a fresh
 * session for the returned staff account.
 */
final class HandoffController
{
    public function __invoke(Request $request, ConsumeHandoff $consume, string $kind, string $token): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant instanceof Tenant, 404);

        // A fresh session id before anything is signed in: nothing from a previous session on
        // this host survives the hand-off.
        $request->session()->invalidate();

        $result = $consume->handle($tenant, $kind, $token);

        $request->session()->regenerate();
        $request->session()->put(ScopeSessionToTenant::KEY, 'tenant:'.$tenant->id);

        if ($result['impersonation'] !== null) {
            $request->session()->put(EnforceImpersonationExpiry::SESSION_KEY, [
                'session' => $result['impersonation'],
                'expires_at' => $result['expires_at']?->getTimestamp(),
            ]);
        }

        return redirect('/');
    }
}
