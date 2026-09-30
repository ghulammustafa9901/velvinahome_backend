<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\V1;

use Dedoc\Scramble\Attributes\QueryParameter;
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
    private const CACHE = 'public, max-age=0, s-maxage=60, stale-while-revalidate=300';

    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly ProductPresenter $presenter,
    ) {}

    #[QueryParameter('filter[category]', description: 'Category slug; includes its subcategories.', type: 'string')]
    #[QueryParameter('filter[brand]', description: 'Brand slug(s), comma-separated.', type: 'string')]
    #[QueryParameter('filter[ids]', description: 'Product ids, comma-separated (at most 100), e.g. for a wishlist.', type: 'string')]
    #[QueryParameter('sort', description: '-published_at (default), published_at, title, -title.', type: 'string')]
    #[QueryParameter('per_page', description: '1–100, default 24.', type: 'integer')]
    #[QueryParameter('cursor', description: 'Opaque cursor from links.next.', type: 'string')]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 24)));
        /** @var array<string, mixed> $filters */
        $filters = (array) $request->query('filter', []);

        $page = $this->catalog->listing($filters, (string) $request->query('sort', '-published_at'))
            ->cursorPaginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => $this->presenter->summaries($page->items()),
            'meta' => ['per_page' => $perPage, 'has_more' => $page->hasMorePages()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ])->header('Cache-Control', self::CACHE);
    }

    public function show(string $ulid): JsonResponse
    {
        $product = $this->catalog->live()->where('ulid', $ulid)->firstOrFail();

        return response()->json(['data' => $this->presenter->detail($product)])->header('Cache-Control', self::CACHE);
    }

    public function bySlug(string $slug, Seo $seo): JsonResponse
    {
        $product = $this->catalog->live()->where('slug', $slug)->first();

        if ($product !== null) {
            return response()->json(['data' => $this->presenter->detail($product)])->header('Cache-Control', self::CACHE);
        }

        $redirect = $seo->resolve('/products/'.$slug);
        abort_if($redirect === null || ! str_starts_with($redirect->toPath, '/products/'), 404);

        $newSlug = substr($redirect->toPath, strlen('/products/'));
        $location = route('api.v1.products.by-slug', ['slug' => $newSlug], false);
        $body = ['redirect' => ['to_path' => $redirect->toPath, 'status_code' => $redirect->statusCode]];

        // The storefront answers the browser with the same status; clients that follow the
        // Location land on the product under its current slug.
        if ($redirect->statusCode === 302) {
            return response()->json($body, 302)->header('Location', $location);
        }

        return response()->json($body, 301)->header('Location', $location);
    }
}
