<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every API, admin and platform response (spec §8). The storefront sets
 * its own in Next.js.
 *
 * API responses are data, never rendered: a CSP that allows nothing. Filament pages need
 * Livewire/Alpine inline scripts, so their CSP covers what does not break them — no framing,
 * no plugins, no foreign form targets or base URLs. HSTS only over HTTPS (the edge also sets
 * it). The server's software and version are never announced.
 */
final class SecurityHeaders
{
    private const API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    private const PANEL_CSP = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $request->is('api/*') ? self::API_CSP : self::PANEL_CSP);
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        return $response;
    }
}
