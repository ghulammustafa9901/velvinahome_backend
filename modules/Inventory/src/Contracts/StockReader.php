<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/**
 * Read-only stock for other modules (storefront listings, search documents, cart
 * revalidation). Available = on_hand − reserved, summed over active locations; computed,
 * never stored (spec §6.5).
 */
interface StockReader
{
    /**
     * @param  array<int>  $variantIds
     * @return array<int, int> variant id => available quantity (0 when never stocked)
     */
    public function available(array $variantIds): array;
}
