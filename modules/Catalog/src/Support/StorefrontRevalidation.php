<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Collection;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Integrations\Contracts\StorefrontCache;

/**
 * Catalog changes that make storefront pages stale (spec §6.13). Registered as model events so
 * every writer — admin, import, console — purges the right ISR tags after commit.
 */
final class StorefrontRevalidation
{
    public static function register(): void
    {
        $invalidate = static fn (array $tags) => app(StorefrontCache::class)->invalidate(array_values(array_filter($tags)));

        $product = static function (Product $product) use ($invalidate): void {
            $invalidate([
                'products',
                'product:'.$product->slug,
                $product->wasChanged('slug') ? 'product:'.$product->getOriginal('slug') : null,
            ]);
        };
        Product::saved($product);
        Product::deleted($product);

        $variant = static function (ProductVariant $variant) use ($invalidate): void {
            $slug = Product::withTrashed()->whereKey($variant->product_id)->value('slug');
            $invalidate(['products', is_string($slug) ? 'product:'.$slug : null]);
        };
        ProductVariant::saved($variant);
        ProductVariant::deleted($variant);

        $category = static fn (Category $category) => $invalidate(['categories', 'category:'.$category->slug, 'products']);
        Category::saved($category);
        Category::deleted($category);

        $collection = static fn (Collection $collection) => $invalidate(['collection:'.$collection->slug]);
        Collection::saved($collection);
        Collection::deleted($collection);
    }
}
