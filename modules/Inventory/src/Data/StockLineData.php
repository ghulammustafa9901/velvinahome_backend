<?php

declare(strict_types=1);

namespace Modules\Inventory\Data;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * One line of a receipt, transfer or count. `unitCostAmount` (minor units) only on receipts.
 */
final class StockLineData extends Data
{
    public function __construct(
        public readonly string $variantUlid,
        #[Min(0)]
        public readonly int $quantity,
        #[Min(0)]
        public readonly ?int $unitCostAmount = null,
    ) {}
}
