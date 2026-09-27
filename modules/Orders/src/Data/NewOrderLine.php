<?php

declare(strict_types=1);

namespace Modules\Orders\Data;

use Modules\Support\ValueObjects\Money;

final readonly class NewOrderLine
{
    /**
     * @param  array<string, string>  $options
     */
    public function __construct(
        public string $variantUlid,
        public string $productUlid,
        public string $sku,
        public string $title,
        public ?string $variantTitle,
        public array $options,
        public ?string $imageUrl,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public int $taxRateBps,
        public Money $total,
        public string $reservationUlid,
    ) {}
}
