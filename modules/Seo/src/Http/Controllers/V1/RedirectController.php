<?php

declare(strict_types=1);

namespace Modules\Seo\Http\Controllers\V1;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Seo\Contracts\Seo;

/**
 * For the storefront: is this unknown path a known old URL? (Critical after a WooCommerce
 * migration, spec §11.) 404 when not.
 */
final class RedirectController
{
    #[QueryParameter('path', description: 'Storefront path, e.g. /product/old-slug.', type: 'string')]
    public function resolve(Request $request, Seo $seo): JsonResponse
    {
        $redirect = $seo->resolve((string) $request->query('path', ''));
        abort_if($redirect === null, 404);

        return response()->json(['data' => [
            'from_path' => $redirect->fromPath,
            'to_path' => $redirect->toPath,
            'status_code' => $redirect->statusCode,
        ]]);
    }
}
