<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Published after the transaction that moved stock commits. Other modules react (search
 * re-index of `in_stock`, storefront revalidation) without Inventory knowing about them.
 */
final class StockChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $variantIds
     */
    public function __construct(public readonly array $variantIds) {}
}
