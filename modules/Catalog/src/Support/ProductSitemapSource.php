<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;
use Modules\Seo\Contracts\SitemapSource;

final class ProductSitemapSource implements SitemapSource
{
    public function name(): string
    {
        return 'products';
    }

    public function count(): int
    {
        return $this->live()->count();
    }

    public function entries(int $offset, int $limit): array
    {
        $entries = [];

        foreach ($this->live()->orderBy('id')->offset($offset)->limit($limit)->get(['slug', 'updated_at']) as $product) {
            $entries[] = ['path' => '/products/'.$product->slug, 'lastmod' => $product->updated_at?->toIso8601ZuluString()];
        }

        return $entries;
    }

    /**
     * @return Builder<Product>
     */
    private function live(): Builder
    {
        return Product::query()
            ->where('status', ProductStatus::Active->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
