<?php

declare(strict_types=1);

namespace Modules\Pricing\Data;

use Modules\Shipping\Data\ShippingOptionData;
use Modules\Support\ValueObjects\Money;
use Modules\Support\ValueObjects\StringMap;

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
     * @param  array<string, string>  $lineIds  variant ULID => cart item ULID
     * @param  array<string, int|null>  $available  variant ULID => sellable units (null: not limited)
     * @return array{lines: list<array{id: string|null, variant_id: string, product_id: string, title: string, variant_title: string|null, sku: string, options: StringMap, image_url: string|null, quantity: int, unit_price: Money, compare_at_price: Money|null, subtotal: Money, discount: Money, tax: Money, total: Money, available_quantity: int|null}>, totals: array{subtotal: Money, discount: Money, shipping: Money, tax: Money, tax_inclusive: bool, total: Money}, coupon: array{code: string, applied: bool, error: string|null}|null, shipping_options: list<array{code: string, name: string, price: Money, cod_allowed: bool, min_days: int|null, max_days: int|null}>, selected_shipping: array{code: string, name: string, price: Money, cod_allowed: bool, min_days: int|null, max_days: int|null}|null, problems: list<array{code: string, variant_id: string}>}
     *
     * @scramble-return array{lines: list<array{id: string|null, variant_id: string, product_id: string, title: string, variant_title: string|null, sku: string, options: \Modules\Support\ValueObjects\StringMap, image_url: string|null, quantity: int, unit_price: \Modules\Support\ValueObjects\Money, compare_at_price: \Modules\Support\ValueObjects\Money|null, subtotal: \Modules\Support\ValueObjects\Money, discount: \Modules\Support\ValueObjects\Money, tax: \Modules\Support\ValueObjects\Money, total: \Modules\Support\ValueObjects\Money, available_quantity: int|null}>, totals: array{subtotal: \Modules\Support\ValueObjects\Money, discount: \Modules\Support\ValueObjects\Money, shipping: \Modules\Support\ValueObjects\Money, tax: \Modules\Support\ValueObjects\Money, tax_inclusive: bool, total: \Modules\Support\ValueObjects\Money}, coupon: array{code: string, applied: bool, error: string|null}|null, shipping_options: list<array{code: string, name: string, price: \Modules\Support\ValueObjects\Money, cod_allowed: bool, min_days: int|null, max_days: int|null}>, selected_shipping: array{code: string, name: string, price: \Modules\Support\ValueObjects\Money, cod_allowed: bool, min_days: int|null, max_days: int|null}|null, problems: list<array{code: string, variant_id: string}>}
     */
    public function toApi(array $lineIds = [], array $available = []): array
    {
        $lines = array_map(
            fn (PricedLine $line): array => $line->toApi($lineIds[$line->variant->ulid] ?? null, $available[$line->variant->ulid] ?? null),
            $this->lines,
        );
        $options = array_map(fn (ShippingOptionData $option): array => $option->toApi(), $this->shippingOptions);

        return [
            'lines' => $lines,
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
            'shipping_options' => $options,
            'selected_shipping' => $this->selectedShipping === null ? null : $this->selectedShipping->toApi(),
            'problems' => $this->problems,
        ];
    }
}
