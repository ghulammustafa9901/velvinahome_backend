<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Cart\Actions\CreateCart;
use Modules\Cart\Actions\SetCartItem;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Actions\PlaceOrder;
use Modules\Checkout\Data\PlaceOrderInput;
use Modules\Customers\Data\AddressData;
use Modules\Notifications\Mail\OrderMail;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use Tests\Support\TestCommerce;
use Tests\Support\TestPlatform;

/*
| Spec §4.8 for commerce data: carts, orders, payments and order emails of one brand are
| invisible to every other brand — including a sibling brand of the same company.
*/

it('keeps carts, orders and payments inside the brand that owns them', function (): void {
    Mail::fake();
    $b = TestPlatform::brand(TestPlatform::BRAND_B);
    $c = TestPlatform::brand(TestPlatform::BRAND_C);

    $placed = $b->run(function (): array {
        $variant = TestCommerce::stockedVariant(100_000, 3);
        $cart = app(CreateCart::class)->handle(null);
        app(SetCartItem::class)->handle($cart['cart'], $variant->ulid, 1);

        return app(PlaceOrder::class)->handle(new PlaceOrderInput(
            cartUlid: $cart['cart']->ulid,
            cartToken: $cart['token'],
            customerId: null,
            email: 'iso-'.Str::lower(Str::random(6)).'@example.test',
            shippingAddress: AddressData::from(TestCommerce::address()),
            billingAddress: null,
            shippingMethodCode: 'standard',
            paymentMethodCode: 'cod',
            note: null,
            expectedTotal: 125_000,
            idempotencyKey: TestCommerce::idempotencyKey(),
            ipAddress: null,
        )) + ['cart' => $cart['cart']->ulid];
    });

    $c->run(function () use ($placed): void {
        expect(Order::query()->where('ulid', $placed['order_id'])->exists())->toBeFalse()
            ->and(Payment::query()->where('order_ulid', $placed['order_id'])->exists())->toBeFalse()
            ->and(Cart::query()->where('ulid', $placed['cart'])->exists())->toBeFalse();
    });

    // The confirmation email was rendered from brand B's own order and settings.
    Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => $mail->order['id'] === $placed['order_id'] && $mail->storeName === 'Brand FIX-B');

    // Brand C's admin cannot open brand B's order.
    $owner = TestPlatform::staff($c, role: 'Owner');
    $this->actingAs($owner, 'staff')
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_C).'/orders/'.$placed['order_id'])
        ->assertNotFound();
});
