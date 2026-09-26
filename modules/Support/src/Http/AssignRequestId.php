<?php

declare(strict_types=1);

namespace Modules\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request gets an id that appears on every log line, in queued jobs dispatched by it
 * (Laravel Context is carried into job payloads) and in the response (spec §9).
 *
 * The storefront BFF sends its own id so one user action can be followed from Next.js into
 * Laravel; an incoming id is accepted only if it looks like an id, never as free text.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::ulid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
