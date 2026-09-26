<?php

declare(strict_types=1);

namespace Modules\Catalog\Listeners;

use Modules\Catalog\Models\Product;
use Modules\Inventory\Events\StockChanged;

/**
 * `in_stock` lives in the search document, so a stock change re-indexes the affected
 * products (queued by Scout on the `search` queue, in this tenant's context).
 */
final class RefreshSearchAfterStockChange
{
    public function handle(StockChanged $event): void
    {
        $products = Product::query()
            ->whereHas('variants', fn ($query) => $query->whereIn('id', $event->variantIds))
            ->get();

        (new Product)->queueMakeSearchable($products);
    }
}
