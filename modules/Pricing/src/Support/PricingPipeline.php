<?php

declare(strict_types=1);

namespace Modules\Pricing\Support;

use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Catalog\Data\SellableVariant;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Data\PricedCart;
use Modules\Pricing\Data\PricedLine;
use Modules\Pricing\Data\PricingRequest;
use Modules\Pricing\Models\Coupon;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Shipping\Contracts\ShippingRates;
use Modules\Shipping\Data\ShippingOptionData;
use Modules\Support\ValueObjects\Money;
use Modules\Tax\Contracts\TaxCalculator;

/**
 * lines → automatic promotions → coupon → shipping → tax → totals (spec §6.4), in integer
 * minor units.
 *
 * - Lines are re-read from the catalog; unknown or unpublished variants are dropped and
 *   reported as problems, never priced from client input.
 * - Automatic promotions come first (PromotionEngine); a coupon then applies to what the
 *   promotions left, so a coupon's percentage is never taken on money already discounted.
 * - An order-level coupon discount is allocated across the eligible lines by value with
 *   Money::allocate, so line totals always add up to the order total.
 * - Tax is computed per line on the discounted amount, in the store's mode; shipping is not
 *   taxed in v1.
 */
final class PricingPipeline implements CartPricer
{
    public function __construct(
        private readonly VariantCatalog $catalog,
        private readonly ShippingRates $shipping,
        private readonly TaxCalculator $tax,
        private readonly StoreSettings $settings,
        private readonly PromotionEngine $promotions,
    ) {}

    public function price(PricingRequest $request): PricedCart
    {
        $currency = $request->currency;
        $variants = $this->catalog->sellable(array_keys($request->lines));
        $problems = [];

        /** @var list<array{variant: SellableVariant, quantity: int, subtotal: Money}> $lines */
        $lines = [];

        foreach ($request->lines as $ulid => $quantity) {
            $variant = $variants[$ulid] ?? null;

            if ($variant === null || ! $variant->purchasable || $variant->price->currency->value !== $currency) {
                $problems[] = ['code' => 'unavailable', 'variant_id' => (string) $ulid];

                continue;
            }

            $lines[] = ['variant' => $variant, 'quantity' => $quantity, 'subtotal' => $variant->price->multipliedBy($quantity)];
        }

        $zero = Money::zero($currency);
        $subtotal = Money::sum(array_map(fn (array $line): Money => $line['subtotal'], $lines), $currency);

        // Automatic promotions, then the coupon on what is left.
        [$promoted, $promotions] = $this->promotions->apply($lines, $zero);
        [$couponed, $couponError, $freeShipping] = $this->applyCoupon($request, $lines, $promoted, $zero);
        $discounts = array_map(fn (Money $promo, Money $coupon): Money => $promo->plus($coupon), $promoted, $couponed);
        $discount = Money::sum($discounts, $currency);

        // Shipping.
        $weight = array_sum(array_map(fn (array $line): int => ($line['variant']->weightGrams ?? 0) * $line['quantity'], $lines));
        $options = $request->shippingAddress === null ? [] : $this->shipping->options($request->shippingAddress, $subtotal->minus($discount), $weight);

        if ($freeShipping) {
            $options = array_map(fn (ShippingOptionData $option): ShippingOptionData => new ShippingOptionData(
                $option->code, $option->name, $zero, $option->codAllowed, $option->minDays, $option->maxDays,
            ), $options);
        }

        $selected = null;
        foreach ($options as $option) {
            if ($option->code === $request->shippingMethodCode) {
                $selected = $option;
            }
        }
        $shipping = $selected->amount ?? $zero;

        // Tax and line totals.
        $inclusive = $this->settings->string('tax.mode', 'inclusive') === 'inclusive';
        $country = $request->shippingAddress->countryCode ?? $this->settings->string('store.country', 'PK');
        $province = $request->shippingAddress?->province;
        $priced = [];

        foreach ($lines as $index => $line) {
            $taxable = $line['subtotal']->minus($discounts[$index]);
            $taxLine = $this->tax->tax($taxable, $country, $province);

            $priced[] = new PricedLine(
                variant: $line['variant'],
                quantity: $line['quantity'],
                unitPrice: $line['variant']->price,
                subtotal: $line['subtotal'],
                discount: $discounts[$index],
                tax: $taxLine->amount,
                taxRateBps: $taxLine->rateBps,
                total: $inclusive ? $taxable : $taxable->plus($taxLine->amount),
            );
        }

        $tax = Money::sum(array_map(fn (PricedLine $line): Money => $line->tax, $priced), $currency);
        $linesTotal = Money::sum(array_map(fn (PricedLine $line): Money => $line->total, $priced), $currency);

        return new PricedCart(
            lines: $priced,
            subtotal: $subtotal,
            discount: $discount,
            shipping: $shipping,
            tax: $tax,
            total: $linesTotal->plus($shipping),
            taxInclusive: $inclusive,
            weightGrams: $weight,
            couponCode: $request->couponCode,
            couponError: $couponError,
            freeShipping: $freeShipping,
            shippingOptions: $options,
            selectedShipping: $selected,
            problems: $problems,
            promotions: $promotions,
            couponDiscount: Money::sum($couponed, $currency),
        );
    }

    /**
     * @param  list<array{variant: SellableVariant, quantity: int, subtotal: Money}>  $lines
     * @param  list<Money>  $promoted  each line's automatic-promotion discount
     * @return array{0: list<Money>, 1: string|null, 2: bool} per-line discounts, error, free shipping
     */
    private function applyCoupon(PricingRequest $request, array $lines, array $promoted, Money $zero): array
    {
        $none = array_fill(0, count($lines), $zero);

        if ($request->couponCode === null || trim($request->couponCode) === '') {
            return [$none, null, false];
        }

        $coupon = CouponRules::find($request->couponCode);

        if (! $coupon instanceof Coupon) {
            return [$none, 'This coupon code is not valid.', false];
        }

        $eligible = array_map(
            fn (array $line): bool => CouponRules::appliesTo($coupon, $line['variant']->productUlid, $line['variant']->categoryUlids),
            $lines,
        );
        // What the customer still pays per line after promotions.
        $net = array_map(fn (array $line, Money $promo): Money => $line['subtotal']->minus($promo), $lines, $promoted);
        $eligibleSubtotal = Money::sum(array_values(array_map(
            fn (Money $amount, bool $ok): Money => $ok ? $amount : $zero,
            $net,
            $eligible,
        )), $zero->currency);

        $problem = CouponRules::problem($coupon, $eligibleSubtotal, $request->customerId, $request->email);

        if ($problem !== null) {
            return [$none, $problem, false];
        }

        if ($coupon->discount_type === 'free_shipping') {
            return [$none, null, true];
        }

        $total = $coupon->discount_type === 'percentage'
            ? $eligibleSubtotal->percentage($coupon->value)
            : Money::min(Money::of($coupon->value, $zero->currency), $eligibleSubtotal);

        $weights = array_map(fn (Money $amount, bool $ok): int => $ok ? $amount->amount : 0, $net, $eligible);

        return [$total->isZero() ? $none : $total->allocate(...$weights), null, false];
    }
}
