<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache policy for every Storefront API response (api-conventions §7), applied to the whole
 * `/api/v1` group so no endpoint can forget it:
 *
 *  - **Private by default.** A response is `no-store` unless its controller explicitly
 *    marked it `public`. Carts, checkout, orders, account data and every error therefore
 *    never land in a shared cache, whether or not anyone remembered.
 *  - **Public GETs get a weak ETag** and answer a matching `If-None-Match` with `304`, so
 *    the BFF and CDN revalidate cheaply.
 */
final class ApiCachePolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $public = $response->headers->hasCacheControlDirective('public');

        if (! $public || $response->getStatusCode() !== 200) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->remove('ETag');

            return $response;
        }

        if ($request->isMethodCacheable() && ($content = $response->getContent()) !== false) {
            $response->setEtag(hash('xxh128', $content), weak: true);
            $response->isNotModified($request);
        }

        return $response;
    }
}
