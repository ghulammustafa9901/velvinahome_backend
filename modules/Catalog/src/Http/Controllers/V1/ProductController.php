<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Queries\CatalogQuery;
use Modules\Catalog\Queries\ProductPresenter;
use Modules\Seo\Contracts\Seo;

/**
 * Public catalog: cursor-paginated listing, product by id, product by slug (following 301s
 * left by slug changes and imports). Cache-friendly; nothing here is per customer.
 */
final class ProductController
{
    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly ProductPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 24)));
        /** @var array<string, mixed> $filters */
        $filters = (array) $request->query('filter', []);

        $page = $this->catalog->listing($filters, (string) $request->query('sort', '-published_at'))
            ->cursorPaginate($perPage)
            ->withQueryString();

        return $this->cacheable(response()->json([
            'data' => $this->presenter->summaries($page->items()),
            'meta' => ['per_page' => $perPage, 'has_more' => $page->hasMorePages()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ]));
    }

    public function show(string $ulid): JsonResponse
    {
        $product = $this->catalog->live()->where('ulid', $ulid)->firstOrFail();

        return $this->cacheable(response()->json(['data' => $this->presenter->detail($product)]));
    }

    public function bySlug(string $slug, Seo $seo): JsonResponse
    {
        $product = $this->catalog->live()->where('slug', $slug)->first();

        if ($product !== null) {
            return $this->cacheable(response()->json(['data' => $this->presenter->detail($product)]));
        }

        $redirect = $seo->resolve('/products/'.$slug);
        abort_if($redirect === null || ! str_starts_with($redirect->toPath, '/products/'), 404);

        $newSlug = substr($redirect->toPath, strlen('/products/'));

        // The storefront answers the browser with the same 301; clients that follow the
        // Location land on the product under its current slug.
        return response()->json([
            'redirect' => ['to_path' => $redirect->toPath, 'status_code' => $redirect->statusCode],
        ], $redirect->statusCode)->header('Location', route('api.v1.products.by-slug', ['slug' => $newSlug], false));
    }

    private function cacheable(JsonResponse $response): JsonResponse
    {
        return $response->setPublic()->setMaxAge(0)->setSharedMaxAge(60)
            ->header('Cache-Control', 'public, max-age=0, s-maxage=60, stale-while-revalidate=300');
    }
}
