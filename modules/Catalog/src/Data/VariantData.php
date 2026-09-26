<?php

declare(strict_types=1);

namespace Modules\Catalog\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * One sellable variant. Prices are integer minor units in the store currency (ADR-0008).
 */
final class VariantData extends Data
{
    /**
     * @param  array<string, string>  $optionValues  option name => value, e.g. ['Color' => 'Red']
     */
    public function __construct(
        #[Regex('/^[A-Za-z0-9._\/-]{1,64}$/')]
        public readonly string $sku,
        #[Min(0)]
        public readonly int $priceAmount,
        #[Min(0)]
        public readonly ?int $compareAtPriceAmount = null,
        #[Max(64)]
        public readonly ?string $barcode = null,
        #[Min(0)]
        public readonly ?int $weightGrams = null,
        public readonly bool $allowBackorder = false,
        public readonly array $optionValues = [],
    ) {}
}
