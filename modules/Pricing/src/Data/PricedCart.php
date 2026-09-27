<?php

declare(strict_types=1);

namespace Modules\Pricing\Data;

use Modules\Shipping\Data\ShippingOptionData;
use Modules\Support\ValueObjects\Money;

/**
 * The output of the pricing pipeline. Totals always reconcile: the sum of line totals plus
 * shipping equals `total`, to the paisa.
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<ShippingOptionData>  $shippingOptions
     * @param  list<array{code: string, variant_id: string}>  $problems  lines that could not be priced
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $shipping,
        public Money $tax,
        public Money $total,
        public bool $taxInclusive,
        public int $weightGrams,
        public ?string $couponCode,
        public ?string $couponError,
        public bool $freeShipping,
        public array $shippingOptions,
        public ?ShippingOptionData $selectedShipping,
        public array $problems,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'lines' => array_map(fn (PricedLine $line): array => $line->toApi(), $this->lines),
            'totals' => [
                'subtotal' => $this->subtotal,
                'discount' => $this->discount,
                'shipping' => $this->shipping,
                'tax' => $this->tax,
                'tax_inclusive' => $this->taxInclusive,
                'total' => $this->total,
            ],
            'coupon' => $this->couponCode === null ? null : [
                'code' => $this->couponCode,
                'applied' => $this->couponError === null,
                'error' => $this->couponError,
            ],
            'shipping_options' => array_map(fn (ShippingOptionData $option): array => $option->toApi(), $this->shippingOptions),
            'selected_shipping' => $this->selectedShipping?->toApi(),
            'problems' => $this->problems,
        ];
    }
}
