<?php

declare(strict_types=1);

namespace Modules\Inventory\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Goods receipt (`locationUlid` + optional supplier), transfer (`fromLocationUlid` →
 * `locationUlid`) or stock count (`locationUlid`), with its lines.
 */
final class StockDocumentData extends Data
{
    /**
     * @param  array<int, StockLineData>  $lines
     */
    public function __construct(
        public readonly string $locationUlid,
        #[DataCollectionOf(StockLineData::class)]
        public readonly array $lines,
        public readonly ?string $fromLocationUlid = null,
        public readonly ?string $supplierUlid = null,
        #[Max(120)]
        public readonly ?string $supplierReference = null,
        #[Max(2000)]
        public readonly ?string $notes = null,
    ) {}
}
