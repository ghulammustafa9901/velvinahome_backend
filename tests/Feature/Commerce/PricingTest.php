<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Customers\Data\AddressData;
use Modules\Pricing\Actions\SaveCoupon;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Data\CouponData;
use Modules\Pricing\Data\PricedCart;
use Modules\Pricing\Data\PricedLine;
use Modules\Pricing\Data\PricingRequest;
use Modules\Settings\Actions\UpdateSetting;
use Modules\Shipping\Actions\SaveShippingMethod;
use Modules\Shipping\Actions\SaveShippingZone;
use Modules\Support\ValueObjects\Money;
use Modules\Tax\Actions\SaveTaxRate;
use Tests\Support\TestCommerce;
use Tests\Support\TestPlatform;

/*
| Spec §6.4: lines → coupon → shipping → tax → totals, integer minor units, recomputed
| server-side; totals always reconcile to the paisa.
*/

beforeEach(function (): void {
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));
    // Fixture tenants are shared across runs: rates and zones are scoped to a unique place.
    $this->province = 'Prov-'.Str::random(6);
    $this->city = 'City-'.Str::random(6);
});

/**
 * @param  array<string, int>  $lines
 */
function price(array $lines, ?string $coupon = null, ?AddressData $address = null, string $method = 'standard'): PricedCart
{
    return app(CartPricer::class)->price(new PricingRequest(
        lines: $lines,
        currency: 'PKR',
        couponCode: $coupon,
        email: 'shopper@example.test',
        shippingAddress: $address ?? AddressData::from(TestCommerce::address()),
        shippingMethodCode: $method,
    ));
}

function reconciles(PricedCart $cart): bool
{
    $lines = array_sum(array_map(fn (PricedLine $line): int => $line->total->amount, $cart->lines));

    return $lines + $cart->shipping->amount === $cart->total->amount;
}

it('extracts inclusive tax without changing the price the customer saw', function (): void {
    app(SaveTaxRate::class)->handle(null, 'GST', 1700, 'PK', $this->province);
    $variant = TestCommerce::stockedVariant(117_000, 5);

    $cart = price([$variant->ulid => 1], address: AddressData::from(TestCommerce::address(['province' => $this->province])));

    expect($cart->tax->amount)->toBe(17_000)
        ->and($cart->taxInclusive)->toBeTrue()
        ->and($cart->lines[0]->taxRateBps)->toBe(1700)
        ->and($cart->total->amount)->toBe(117_000 + 25_000)
        ->and(reconciles($cart))->toBeTrue();

    // Another province of the same country has no rate.
    expect(price([$variant->ulid => 1])->tax->amount)->toBe(0);
});

it('adds exclusive tax on top when the brand prices without tax', function (): void {
    app(SaveTaxRate::class)->handle(null, 'GST', 1700, 'PK', $this->province);
    $variant = TestCommerce::stockedVariant(117_000, 5);

    app(UpdateSetting::class)->handle('tax', 'mode', 'exclusive');

    try {
        $cart = price([$variant->ulid => 1], address: AddressData::from(TestCommerce::address(['province' => $this->province])));

        expect($cart->tax->amount)->toBe(19_890)
            ->and($cart->taxInclusive)->toBeFalse()
            ->and($cart->total->amount)->toBe(117_000 + 19_890 + 25_000)
            ->and(reconciles($cart))->toBeTrue();
    } finally {
        app(UpdateSetting::class)->handle('tax', 'mode', 'inclusive');
    }
});

it('applies a product-scoped percentage coupon only to that product', function (): void {
    $scoped = TestCommerce::stockedVariant(100_000, 5);
    $other = TestCommerce::stockedVariant(50_000, 5);
    $code = 'TEN'.strtoupper(Str::random(6));
    app(SaveCoupon::class)->handle(null, new CouponData(code: $code, discountType: 'percentage', value: 1000, productUlids: [$scoped->product->ulid]));

    $cart = price([$scoped->ulid => 2, $other->ulid => 1], strtolower($code));

    $byVariant = collect($cart->lines)->keyBy(fn (PricedLine $line): string => $line->variant->ulid);

    expect($cart->couponError)->toBeNull()
        ->and($cart->discount->amount)->toBe(20_000)
        ->and($byVariant[$scoped->ulid]->discount->amount)->toBe(20_000)
        ->and($byVariant[$other->ulid]->discount->amount)->toBe(0)
        ->and(reconciles($cart))->toBeTrue();
});

it('allocates a fixed coupon across lines to the paisa and enforces minimum spend', function (): void {
    $a = TestCommerce::stockedVariant(33_333, 5);
    $b = TestCommerce::stockedVariant(66_667, 5);
    $code = 'FLAT'.strtoupper(Str::random(6));
    app(SaveCoupon::class)->handle(null, new CouponData(code: $code, discountType: 'fixed', value: 10_001, minimumSpendAmount: 150_000));

    $small = price([$a->ulid => 1], $code);
    expect($small->couponError)->not->toBeNull()
        ->and($small->discount->amount)->toBe(0);

    $cart = price([$a->ulid => 3, $b->ulid => 1], $code);

    expect($cart->couponError)->toBeNull()
        ->and($cart->discount->amount)->toBe(10_001)
        ->and(array_sum(array_map(fn (PricedLine $line): int => $line->discount->amount, $cart->lines)))->toBe(10_001)
        ->and(reconciles($cart))->toBeTrue();
});

it('makes shipping free with a free-shipping coupon and over the threshold', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 10);
    $code = 'SHIP'.strtoupper(Str::random(6));
    app(SaveCoupon::class)->handle(null, new CouponData(code: $code, discountType: 'free_shipping'));

    expect(price([$variant->ulid => 1])->shipping->amount)->toBe(25_000)
        ->and(price([$variant->ulid => 1], $code)->shipping->amount)->toBe(0)
        // Rs 5,000 of goods reaches the default free-shipping threshold.
        ->and(price([$variant->ulid => 5])->shipping->amount)->toBe(0);
});

it('prefers the most specific shipping zone and respects its COD rule', function (): void {
    $zone = app(SaveShippingZone::class)->handle(null, 'Local '.$this->city, ['pk'], [], [$this->city]);
    app(SaveShippingMethod::class)->handle($zone, null, 'express', 'Same-day courier', Money::of(50_000, 'PKR'), codAllowed: false, minDays: 0, maxDays: 1);
    $variant = TestCommerce::stockedVariant(100_000, 5);

    $local = AddressData::from(TestCommerce::address(['city' => $this->city]));
    $cart = price([$variant->ulid => 1], address: $local, method: 'express');

    expect(array_map(fn ($option): string => $option->code, $cart->shippingOptions))->toBe(['express'])
        ->and($cart->shipping->amount)->toBe(50_000)
        ->and($cart->selectedShipping?->codAllowed)->toBeFalse();

    // Everywhere else the nationwide zone still applies.
    expect(array_map(fn ($option): string => $option->code, price([$variant->ulid => 1])->shippingOptions))->toBe(['standard']);
});

it('reports unavailable lines instead of pricing them', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $draft = TestCommerce::stockedVariant(100_000, 5);
    app(Modules\Catalog\Actions\ChangeProductStatus::class)->handle($draft->product, Modules\Catalog\Enums\ProductStatus::Draft);

    $cart = price([$variant->ulid => 1, $draft->ulid => 1, '01HZZZZZZZZZZZZZZZZZZZZZZZ' => 1]);

    expect($cart->lines)->toHaveCount(1)
        ->and(array_column($cart->problems, 'variant_id'))->toBe([$draft->ulid, '01HZZZZZZZZZZZZZZZZZZZZZZZ'])
        ->and($cart->subtotal->amount)->toBe(100_000);
});
