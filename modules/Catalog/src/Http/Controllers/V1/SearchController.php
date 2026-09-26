<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Queries\ProductSearchQuery;

final class SearchController
{
    public function __construct(private readonly ProductSearchQuery $search) {}

    public function search(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $filters */
        $filters = (array) $request->query('filter', []);

        return response()->json($this->search->search(
            mb_substr((string) $request->query('q', ''), 0, 200),
            $filters,
            (string) $request->query('sort', 'relevance'),
            max(1, (int) $request->integer('page', 1)),
            min(100, max(1, (int) $request->integer('per_page', 24))),
        ))->header('Cache-Control', 'public, max-age=0, s-maxage=30');
    }

    public function suggest(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return response()->json([
            'data' => $q === '' ? [] : $this->search->suggest($q, min(10, max(1, (int) $request->integer('limit', 8)))),
        ])->header('Cache-Control', 'public, max-age=0, s-maxage=30');
    }
}
