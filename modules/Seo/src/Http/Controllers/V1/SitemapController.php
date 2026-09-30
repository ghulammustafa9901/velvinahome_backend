<?php

declare(strict_types=1);

namespace Modules\Seo\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Modules\Seo\Support\SitemapRegistry;

/**
 * Data for the storefront's chunked sitemap.xml (spec §7.5). Paths only: the storefront adds
 * its own origin, so the same data serves every domain of the brand.
 */
final class SitemapController
{
    private const CACHE = 'public, max-age=0, s-maxage=3600, stale-while-revalidate=86400';

    public function index(SitemapRegistry $sitemap): JsonResponse
    {
        return response()->json(['data' => $sitemap->chunks()])->header('Cache-Control', self::CACHE);
    }

    public function chunk(string $chunk, SitemapRegistry $sitemap): JsonResponse
    {
        $entries = $sitemap->chunk($chunk);
        abort_if($entries === null, 404);

        return response()->json(['data' => $entries])->header('Cache-Control', self::CACHE);
    }
}
