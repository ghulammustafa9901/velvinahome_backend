<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Modules\Settings\Contracts\FeatureFlags;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:{flag}` — a switched-off feature answers 403 `feature_disabled`, never a blank page
 * or a misleading 404, so the storefront can tell "switched off" from "not found".
 */
final class EnsureFeatureEnabled
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->flags->enabled($feature)) {
            return response()->json([
                'error' => [
                    'code' => 'feature_disabled',
                    'message' => "The {$feature} feature is not enabled for this store.",
                    'request_id' => Context::get('request_id'),
                ],
            ], 403);
        }

        return $next($request);
    }
}
