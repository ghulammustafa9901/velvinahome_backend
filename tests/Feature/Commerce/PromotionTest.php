<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Actions\CreateCart;
use Modules\Cart\Actions\SetCartItem;
use Modules\Catalog\Models\ProductVariant;
use Modules\Checkout\Actions\PlaceOrder;
use Modules\Checkout\Data\PlaceOrderInput;
use Modules\Customers\Data\AddressData;
use Modules\Orders\Models\Order;
use Modules\Pricing\Actions\SaveCoupon;
use Modules\Pricing\Actions\SavePromotion;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Data\CouponData;
use Modules\Pricing\Data\PricedCart;
use Modules\Pricing\Data\PricedLine;
use Modules\Pricing\Data\PricingRequest;
use Modules\Pricing\Models\Promotion;
use Tests\Support\TestCommerce;
use Tests\Support\TestStore;

/*
| Automatic promotions (spec §13 phase 6): priced before coupons, one per item, spread over
| lines with exact allocation, recorded on the order. Every promotion here is limited to the
| test's own new products, because the test store is shared.
*/

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    Promotion::query()->where('name', 'like', 'TEST %')->delete();
});

/**
 * @param  array<string, mixed>  $config
 * @param  list<ProductVariant>  $for
 */
function promotion(string $type, array $config, array $for, int $priority = 0, ?CarbonImmutable $startsAt = null, ?CarbonImmutable $endsAt = null): Promotion
{
    return app(SavePromotion::class)->handle(
        null, 'TEST '.Str::random(6), $type, $config, true, $priority, $startsAt, $endsAt,
        productUlids: array_map(fn (ProductVariant $variant): string => $variant->product->ulid, $for),
    );
}

/**
 * @param  array<string, int>  $lines
 */
function quote(array $lines, ?string $coupon = null): PricedCart
{
    return app(CartPricer::class)->price(new PricingRequest(
        lines: $lines, currency: 'PKR', couponCode: $coupon, email: 'promo@example.test',
        shippingAddress: AddressData::from(TestCommerce::address()), shippingMethodCode: 'standard',
    ));
}

function lineOf(PricedCart $cart, ProductVariant $variant): PricedLine
{
    foreach ($cart->lines as $line) {
        if ($line->variant->ulid === $variant->ulid) {
            return $line;
        }
    }

    throw new RuntimeException('line not found');
}

function balanced(PricedCart $cart): bool
{
    $lines = array_sum(array_map(fn (PricedLine $line): int => $line->total->amount, $cart->lines));

    return $lines + $cart->shipping->amount === $cart->total->amount
        && array_sum(array_map(fn (PricedLine $line): int => $line->discount->amount, $cart->lines)) === $cart->discount->amount;
}

it('takes a percentage sale off the products it covers only', function (): void {
    $onSale = TestCommerce::stockedVariant(100_000, 5);
    $other = TestCommerce::stockedVariant(50_000, 5);
    $sale = promotion('percentage_sale', ['percent_bps' => 2000], [$onSale]);

    $cart = quote([$onSale->ulid => 2, $other->ulid => 1]);

    expect(lineOf($cart, $onSale)->discount->amount)->toBe(40_000)
        ->and(lineOf($cart, $other)->discount->amount)->toBe(0)
        ->and($cart->promotions)->toHaveCount(1)
        ->and($cart->promotions[0]['name'])->toBe($sale->name)
        ->and($cart->promotions[0]['amount']->amount)->toBe(40_000)
        ->and(balanced($cart))->toBeTrue();
});

it('gives the highest spend tier reached, spread over the lines', function (): void {
    $a = TestCommerce::stockedVariant(150_000, 10);
    $b = TestCommerce::stockedVariant(70_001, 10);
    promotion('spend_tier', ['tiers' => [
        ['min_spend' => 200_000, 'type' => 'percentage', 'value' => 1000],
        ['min_spend' => 500_000, 'type' => 'fixed', 'value' => 75_000],
    ]], [$a, $b]);

    expect(quote([$a->ulid => 1])->discount->amount)->toBe(0)
        ->and(quote([$a->ulid => 2])->discount->amount)->toBe(30_000);

    $big = quote([$a->ulid => 3, $b->ulid => 1]);   // 520,001 → fixed 75,000

    expect($big->discount->amount)->toBe(75_000)
        ->and(lineOf($big, $a)->discount->amount + lineOf($big, $b)->discount->amount)->toBe(75_000)
        ->and(balanced($big))->toBeTrue();
});

it('makes the cheapest item of every buy-2-get-1 group free', function (): void {
    $dear = TestCommerce::stockedVariant(100_000, 10);
    $cheap = TestCommerce::stockedVariant(60_000, 10);
    promotion('buy_x_get_y', ['buy' => 2, 'get' => 1, 'percent_bps' => 10000], [$dear, $cheap]);

    $three = quote([$dear->ulid => 2, $cheap->ulid => 1]);
    expect(lineOf($three, $cheap)->discount->amount)->toBe(60_000)
        ->and(lineOf($three, $dear)->discount->amount)->toBe(0);

    // Four items: one full group (three dearest), the fourth pays full price.
    $four = quote([$dear->ulid => 3, $cheap->ulid => 1]);
    expect(lineOf($four, $dear)->discount->amount)->toBe(100_000)
        ->and(lineOf($four, $cheap)->discount->amount)->toBe(0)
        ->and(balanced($four))->toBeTrue();
});

it('never stacks two promotions on one item; priority decides', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    promotion('spend_tier', ['tiers' => [['min_spend' => 1, 'type' => 'percentage', 'value' => 5000]]], [$variant], priority: 5);
    $first = promotion('percentage_sale', ['percent_bps' => 1000], [$variant], priority: 1);

    $cart = quote([$variant->ulid => 1]);

    expect($cart->discount->amount)->toBe(10_000)
        ->and(array_column($cart->promotions, 'name'))->toBe([$first->name]);
});

it('applies a coupon to what the promotion left', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    promotion('percentage_sale', ['percent_bps' => 2000], [$variant]);
    $code = 'PROMO'.strtoupper(Str::random(6));
    app(SaveCoupon::class)->handle(null, new CouponData(code: $code, discountType: 'percentage', value: 1000, productUlids: [$variant->product->ulid]));

    $cart = quote([$variant->ulid => 1], $code);

    // 20% of 100,000 = 20,000; then 10% of the remaining 80,000 = 8,000.
    expect($cart->discount->amount)->toBe(28_000)
        ->and($cart->couponDiscount?->amount)->toBe(8_000)
        ->and($cart->toApi()['coupon']['discount']?->amount)->toBe(8_000)
        ->and($cart->couponError)->toBeNull()
        ->and(balanced($cart))->toBeTrue();
});

it('runs only within its dates', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    promotion('percentage_sale', ['percent_bps' => 5000], [$variant], startsAt: CarbonImmutable::now()->addDay());
    promotion('percentage_sale', ['percent_bps' => 5000], [$variant], startsAt: CarbonImmutable::now()->subDays(3), endsAt: CarbonImmutable::now()->subDay());

    expect(quote([$variant->ulid => 1])->discount->amount)->toBe(0);
});

it('records the promotions on the order it was placed with', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $sale = promotion('percentage_sale', ['percent_bps' => 1500], [$variant]);

    $cart = app(CreateCart::class)->handle(null);
    app(SetCartItem::class)->handle($cart['cart'], $variant->ulid, 2);

    $placed = app(PlaceOrder::class)->handle(new PlaceOrderInput(
        cartUlid: $cart['cart']->ulid, cartToken: $cart['token'], customerId: null, email: 'promo-order@example.test',
        shippingAddress: AddressData::from(TestCommerce::address()), billingAddress: null, shippingMethodCode: 'standard',
        paymentMethodCode: 'cod', note: null, expectedTotal: 200_000 - 30_000 + 25_000,
        idempotencyKey: TestCommerce::idempotencyKey(), ipAddress: null,
    ));

    $order = Order::query()->where('ulid', $placed['order_id'])->firstOrFail();

    expect($order->discount->amount)->toBe(30_000)
        ->and($order->promotions)->toBe([['name' => $sale->name, 'amount' => 30_000]])
        ->and($order->items->first()?->discount->amount)->toBe(30_000);
});

it('checks the configuration and who may save a promotion', function (): void {
    $save = fn (string $type, array $config, $actor = null) => app(SavePromotion::class)->handle(null, 'TEST bad', $type, $config, actor: $actor);

    expect(fn () => $save('percentage_sale', ['percent_bps' => 0]))->toThrow(ValidationException::class)
        ->and(fn () => $save('percentage_sale', ['percent_bps' => 10001]))->toThrow(ValidationException::class)
        ->and(fn () => $save('buy_x_get_y', ['buy' => 0, 'get' => 1, 'percent_bps' => 10000]))->toThrow(ValidationException::class)
        ->and(fn () => $save('spend_tier', ['tiers' => []]))->toThrow(ValidationException::class)
        ->and(fn () => $save('spend_tier', ['tiers' => [['min_spend' => 100, 'type' => 'percentage', 'value' => 20000]]]))->toThrow(ValidationException::class)
        ->and(fn () => $save('free_gift', []))->toThrow(ValidationException::class)
        ->and(fn () => $save('percentage_sale', ['percent_bps' => 1000], TestStore::staff(role: 'Order Handler')))
        ->toThrow(AuthorizationException::class);
});
