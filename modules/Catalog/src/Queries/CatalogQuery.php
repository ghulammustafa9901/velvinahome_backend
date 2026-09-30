<?php

declare(strict_types=1);

namespace Modules\Catalog\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;

/**
 * Read side of the storefront catalog. Only live products: active, published in the past,
 * not deleted. Filter and sort keys are allowlisted; anything else is a 422, never silently
 * ignored (api-conventions §5).
 */
final class CatalogQuery
{
    public const FILTERS = ['category', 'brand', 'ids'];

    /** @var array<string, array{string, string}> sort key => [column, direction] */
    public const SORTS = [
        '-published_at' => ['published_at', 'desc'],
        'published_at' => ['published_at', 'asc'],
        'title' => ['title', 'asc'],
        '-title' => ['title', 'desc'],
    ];

    /**
     * @return Builder<Product>
     */
    public function live(): Builder
    {
        return Product::query()
            ->where('status', ProductStatus::Active->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with(['variants', 'brand', 'media']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function listing(array $filters, string $sort): Builder
    {
        $unknown = array_diff(array_keys($filters), self::FILTERS);

        if ($unknown !== [] || ! isset(self::SORTS[$sort])) {
            throw ValidationException::withMessages(array_filter([
                'filter' => $unknown === [] ? null : ['Unsupported filter: '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::FILTERS).'.'],
                'sort' => isset(self::SORTS[$sort]) ? null : ['Unsupported sort. Allowed: '.implode(', ', array_keys(self::SORTS)).'.'],
            ]));
        }

        $query = $this->live();

        if (isset($filters['category'])) {
            $category = Category::query()->where('slug', (string) $filters['category'])->where('is_visible', true)->first();

            // An unknown category yields an empty list, not an error: links to removed
            // categories keep rendering.
            $query->whereHas('categories', fn ($q) => $q->whereIn(
                'categories.id',
                $category === null ? [0] : $category->descendantsAndSelf()->pluck('categories.id'),
            ));
        }

        if (isset($filters['brand'])) {
            $slugs = array_filter(explode(',', (string) $filters['brand']));
            $query->whereIn('brand_id', Brand::query()->whereIn('slug', $slugs)->pluck('id'));
        }

        if (isset($filters['ids'])) {
            // Product cards for a known set (wishlist, recently viewed): at most one page.
            $ids = array_slice(array_values(array_filter(explode(',', (string) $filters['ids']), fn (string $id): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1)), 0, 100);
            $query->whereIn('ulid', $ids === [] ? [''] : $ids);
        }

        [$column, $direction] = self::SORTS[$sort];

        return $query->orderBy($column, $direction)->orderBy('id', $direction);
    }
}
