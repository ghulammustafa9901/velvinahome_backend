<?php

declare(strict_types=1);

namespace Modules\Pricing\Data;

use Modules\Catalog\Data\SellableVariant;
use Modules\Support\ValueObjects\Money;
use Modules\Support\ValueObjects\StringMap;

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
     * @param  string|null  $lineId  the cart item ULID, when priced for a cart
     * @param  int|null  $availableQuantity  sellable units now; null when not limited (backorders) or not asked
     * @return array{id: string|null, variant_id: string, product_id: string, title: string, variant_title: string|null, sku: string, options: StringMap, image_url: string|null, quantity: int, unit_price: Money, compare_at_price: Money|null, subtotal: Money, discount: Money, tax: Money, total: Money, available_quantity: int|null}
     */
    public function toApi(?string $lineId = null, ?int $availableQuantity = null): array
    {
        return [
            'id' => $lineId,
            'variant_id' => $this->variant->ulid,
            'product_id' => $this->variant->productUlid,
            'title' => $this->variant->productTitle,
            'variant_title' => $this->variant->variantTitle,
            'sku' => $this->variant->sku,
            'options' => StringMap::from($this->variant->optionValues),
            'image_url' => $this->variant->imageUrl,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'compare_at_price' => $this->variant->compareAtPrice,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total' => $this->total,
            'available_quantity' => $availableQuantity,
        ];
    }
}
