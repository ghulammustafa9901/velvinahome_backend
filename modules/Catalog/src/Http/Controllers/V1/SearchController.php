<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\V1;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Queries\ProductSearchQuery;

final class SearchController
{
    public function __construct(private readonly ProductSearchQuery $search) {}

    #[QueryParameter('q', description: 'Search text; typo-tolerant on words, exact on SKUs.', type: 'string')]
    #[QueryParameter('filter[category]', description: 'Category id(s) from the category facet, comma-separated.', type: 'string')]
    #[QueryParameter('filter[brand]', description: 'Brand name(s) from the brand facet, comma-separated.', type: 'string')]
    #[QueryParameter('filter[in_stock]', type: 'boolean')]
    #[QueryParameter('filter[price][gte]', description: 'Minimum price, minor units.', type: 'integer')]
    #[QueryParameter('filter[price][lte]', description: 'Maximum price, minor units.', type: 'integer')]
    #[QueryParameter('sort', description: 'relevance (default), price, -price, newest, best_selling.', type: 'string')]
    #[QueryParameter('page', type: 'integer')]
    #[QueryParameter('per_page', description: '1–100, default 24.', type: 'integer')]
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

    #[QueryParameter('q', type: 'string')]
    #[QueryParameter('limit', description: '1–10, default 8.', type: 'integer')]
    public function suggest(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return response()->json([
            'data' => $q === '' ? [] : $this->search->suggest($q, min(10, max(1, (int) $request->integer('limit', 8)))),
        ])->header('Cache-Control', 'public, max-age=0, s-maxage=30');
    }
}
