<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Contracts\VariantLookup;
use Modules\Catalog\Models\ProductVariant;

final class EloquentVariantLookup implements VariantLookup
{
    public function search(string $term, int $limit = 25): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))).'%';

        $results = [];

        $variants = ProductVariant::query()
            ->with('product')
            ->where(fn ($query) => $query
                ->whereRaw('lower(sku) like ?', [$like])
                ->orWhereRaw('lower(barcode) like ?', [$like])
                ->orWhereHas('product', fn ($product) => $product->whereRaw('lower(title) like ?', [$like])))
            ->orderBy('sku')
            ->limit($limit)
            ->get();

        foreach ($variants as $variant) {
            $results[$variant->ulid] = trim($variant->sku.' — '.$variant->product->title.' '.($variant->title ?? ''));
        }

        return $results;
    }
}
