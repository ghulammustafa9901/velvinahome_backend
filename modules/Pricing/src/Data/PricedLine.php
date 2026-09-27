<?php

declare(strict_types=1);

namespace Modules\Pricing\Data;

use Modules\Catalog\Data\SellableVariant;
use Modules\Support\ValueObjects\Money;

final readonly class PricedLine
{
    public function __construct(
        public SellableVariant $variant,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public int $taxRateBps,
        public Money $total,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'variant_id' => $this->variant->ulid,
            'product_id' => $this->variant->productUlid,
            'title' => $this->variant->productTitle,
            'variant_title' => $this->variant->variantTitle,
            'sku' => $this->variant->sku,
            'options' => (object) $this->variant->optionValues,
            'image_url' => $this->variant->imageUrl,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'compare_at_price' => $this->variant->compareAtPrice,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total' => $this->total,
        ];
    }
}
