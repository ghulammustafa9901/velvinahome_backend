<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Seo\Contracts\Seo;

// For the storefront's middleware: is this unknown path a known old URL? (Critical after a
// WooCommerce migration, spec §11.) 404 when not.
Route::middleware('throttle:catalog')->get('redirects/resolve', function (Request $request, Seo $seo): JsonResponse {
    $redirect = $seo->resolve((string) $request->query('path', ''));
    abort_if($redirect === null, 404);

    return response()->json(['data' => [
        'from_path' => $redirect->fromPath,
        'to_path' => $redirect->toPath,
        'status_code' => $redirect->statusCode,
    ]]);
})->name('redirects.resolve');
