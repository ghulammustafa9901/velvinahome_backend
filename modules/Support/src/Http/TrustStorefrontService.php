<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recognises calls from the store's own storefront server (ADR-0002, ADR-0003), signed with
 * SERVICE_AUTH_SECRET. It authenticates the caller only and grants nothing else.
 *
 *  - `X-Storefront-Render: hmac("storefront-render:v1")` — page rendering (ISR). Constant so it
 *    stays part of a cacheable request; such requests are not rate-limited per server IP.
 *  - `X-Storefront-Signature: v1,<unix ts>,hmac("<ts>\n<ip>")` + `X-Storefront-Client-Ip` — a
 *    customer action through the BFF; the API then sees, logs and rate-limits the customer's
 *    own IP instead of the storefront server's.
 *
 * Anything missing or invalid is simply ignored: the request is treated as an ordinary one.
 */
final class TrustStorefrontService
{
    public const RENDER_ATTRIBUTE = 'storefront_render';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('store.service_auth.secret');

        if (is_string($secret) && strlen($secret) >= 32) {
            $this->recognise($request, $secret);
        }

        return $next($request);
    }

    private function recognise(Request $request, string $secret): void
    {
        $render = $request->headers->get('X-Storefront-Render');

        if (is_string($render) && hash_equals(hash_hmac('sha256', 'storefront-render:v1', $secret), $render)) {
            $request->attributes->set(self::RENDER_ATTRIBUTE, true);

            return;
        }

        $signature = explode(',', (string) $request->headers->get('X-Storefront-Signature', ''));
        $ip = (string) $request->headers->get('X-Storefront-Client-Ip', '');

        if (count($signature) !== 3 || $signature[0] !== 'v1' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return;
        }

        [, $timestamp, $mac] = $signature;
        $maxAge = (int) config('store.service_auth.max_age_seconds', 60);

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $maxAge) {
            return;
        }

        if (! hash_equals(hash_hmac('sha256', $timestamp."\n".$ip, $secret), $mac)) {
            return;
        }

        // Resolve what the (trusted) proxy said about host and scheme first: once REMOTE_ADDR is
        // the customer's, the forwarded headers are no longer trusted, and the host and scheme
        // would be lost.
        $host = $request->getHost();
        $port = $request->getPort();
        $secure = $request->isSecure();

        $request->headers->set('HOST', $host.(in_array((int) $port, [0, 80, 443], true) ? '' : ':'.$port));
        $request->server->set('HTTP_HOST', $request->headers->get('HOST'));
        $request->server->set('HTTPS', $secure ? 'on' : 'off');
        $request->headers->remove('X-Forwarded-Host');
        $request->headers->remove('X-Forwarded-Proto');
        $request->headers->remove('X-Forwarded-Port');

        // The customer's address becomes the request's address: rate limits, login audits and
        // logs see the real client, not the storefront server.
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->remove('X-Forwarded-For');
    }
}
