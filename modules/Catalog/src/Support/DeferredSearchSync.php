<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Models\Product;

/**
 * Bulk writes (imports) without a search job per saved product and per stock change: inside
 * run(), Scout's per-save syncing is off and touched products are only collected; at the end
 * they are indexed (or removed) in chunks — a 5,000-product import queues ~10 jobs instead of
 * ~15,000 (docs/performance/load-test.md).
 */
final class DeferredSearchSync
{
    private const CHUNK = 500;

    private int $depth = 0;

    /** @var array<int, true> */
    private array $productIds = [];

    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @param  iterable<int>  $productIds
     */
    public function touch(iterable $productIds): void
    {
        foreach ($productIds as $id) {
            $this->productIds[(int) $id] = true;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        if ($this->depth > 0) {
            return $work();
        }

        $this->depth++;

        try {
            return Product::withoutSyncingToSearch($work);
        } finally {
            $this->depth--;
            $this->flush();
        }
    }

    private function flush(): void
    {
        $ids = array_keys($this->productIds);
        $this->productIds = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $products = Product::query()->whereIn('id', $chunk)->get();
            $searchable = fn (Product $product): bool => $product->shouldBeSearchable();

            (new Product)->queueMakeSearchable($products->filter($searchable)->values());
            (new Product)->queueRemoveFromSearch($products->reject($searchable)->values());
        }
    }
}
