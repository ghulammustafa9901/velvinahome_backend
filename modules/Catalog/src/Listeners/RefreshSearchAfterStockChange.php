<?php

declare(strict_types=1);

namespace Modules\Catalog\Listeners;

use Modules\Catalog\Models\Product;
use Modules\Catalog\Support\DeferredSearchSync;
use Modules\Inventory\Events\StockChanged;

/**
 * `in_stock` lives in the search document, so a stock change re-indexes the affected
 * products (queued by Scout on the `search` queue). During a bulk
 * run the products are only collected and indexed together at its end.
 */
final class RefreshSearchAfterStockChange
{
    public function __construct(private readonly DeferredSearchSync $deferred) {}

    public function handle(StockChanged $event): void
    {
        $products = Product::query()
            ->whereHas('variants', fn ($query) => $query->whereIn('id', $event->variantIds))
            ->get();

        if ($this->deferred->active()) {
            $this->deferred->touch($products->modelKeys());

            return;
        }

        (new Product)->queueMakeSearchable($products);
    }
}
