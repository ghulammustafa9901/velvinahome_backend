<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\ProductVariant;
use Modules\Integrations\Models\OutboxMessage;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockReservation;
use Modules\Notifications\Mail\OrderMail;
use Modules\Orders\Models\Order;
use Modules\Payments\Contracts\Payments;
use Modules\Payments\Models\Payment;
use Modules\Pricing\Actions\SaveCoupon;
use Modules\Pricing\Data\CouponData;
use Tests\Support\TestCommerce;
use Tests\Support\TestPlatform;

/*
| Phase 3 exit criteria: a customer checks out via COD and manual transfer through the API;
| stock, payments and order state stay consistent under failure and retry.
*/

beforeEach(function (): void {
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1';
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_B);
});

/**
 * @return array{0: string, 1: string} cart id, cart token
 */
function guestCart(object $test, ProductVariant $variant, int $quantity = 1): array
{
    $created = $test->postJson($test->api.'/carts')->assertCreated();
    $cart = (string) $created->json('data.id');
    $token = (string) $created->json('data.token');

    $test->withHeader('X-Cart-Token', $token)
        ->postJson($test->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => $quantity])
        ->assertOk();

    return [$cart, $token];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderBody(string $cart, int $expectedTotal, array $overrides = []): array
{
    return array_merge([
        'cart_id' => $cart,
        'email' => 'buyer-'.Str::lower(Str::random(6)).'@example.test',
        'shipping_address' => TestCommerce::address(),
        'shipping_method' => 'standard',
        'payment_method' => 'cod',
        'expected_total' => $expectedTotal,
    ], $overrides);
}

it('checks out a guest cart with Cash on Delivery end to end', function (): void {
    Mail::fake();
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(120_000, 5));
    [$cart, $token] = guestCart($this, $variant, 2);

    $quote = $this->withHeader('X-Cart-Token', $token)->postJson($this->api.'/checkout/quote', [
        'cart_id' => $cart,
        'shipping_address' => TestCommerce::address(),
        'shipping_method' => 'standard',
    ])->assertOk();

    // Rs 2,400 of goods is under the free-shipping threshold: flat Rs 250 shipping.
    expect($quote->json('data.totals.subtotal.amount'))->toBe(240_000)
        ->and($quote->json('data.totals.shipping.amount'))->toBe(25_000)
        ->and($quote->json('data.totals.total.amount'))->toBe(265_000)
        ->and(collect($quote->json('data.payment_methods'))->pluck('code')->all())->toContain('cod');

    $placed = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 265_000))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false');

    $orderId = (string) $placed->json('data.order_id');
    $accessToken = (string) $placed->json('data.access_token');

    $this->brand->run(function () use ($orderId, $variant, $cart): void {
        $order = Order::query()->where('ulid', $orderId)->firstOrFail();

        expect($order->status::getMorphClass())->toBe('awaiting_confirmation')
            ->and($order->payment_status)->toBe('unpaid')
            ->and($order->total->amount)->toBe(265_000)
            ->and($order->items)->toHaveCount(1)
            ->and($order->items->first()?->unit_price->amount)->toBe(120_000);

        // ADR-0010: a COD sale is committed at placement.
        expect(TestCommerce::stock($variant))->toBe(['on_hand' => 3, 'reserved' => 0])
            ->and((int) InventoryMovement::query()->where('variant_id', $variant->id)->where('type', 'sale')->sum('quantity'))->toBe(-2)
            ->and(Payment::query()->where('order_ulid', $orderId)->value('status'))->toBe('pending')
            ->and(Cart::query()->where('ulid', $cart)->value('status'))->toBe('converted')
            ->and(OutboxMessage::query()->where('event_type', 'order.placed')->where('payload->order_ulid', $orderId)->exists())->toBeTrue();
    });

    // The order confirmation email went out after commit.
    Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => $mail->order['id'] === $orderId);

    $this->withHeader('X-Order-Token', $accessToken)->getJson($this->api.'/orders/'.$orderId)
        ->assertOk()
        ->assertJsonPath('data.status', 'awaiting_confirmation')
        ->assertJsonPath('data.totals.total.amount', 265_000)
        ->assertJsonPath('data.payment.method', 'cod')
        ->assertJsonPath('data.timeline.0.status', 'awaiting_confirmation');
});

it('never shows an order without its token, or on another brand', function (): void {
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(50_000, 3));
    [$cart, $token] = guestCart($this, $variant);

    $placed = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 75_000))
        ->assertCreated();

    $orderId = (string) $placed->json('data.order_id');
    $accessToken = (string) $placed->json('data.access_token');

    $this->getJson($this->api.'/orders/'.$orderId)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->withHeader('X-Order-Token', 'wrong-'.$accessToken)->getJson($this->api.'/orders/'.$orderId)->assertNotFound();

    // Sibling brand of the same company: the order does not exist there at all.
    $this->withHeader('X-Order-Token', $accessToken)
        ->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/orders/'.$orderId)
        ->assertNotFound();
});

it('replays the same order for a retried Idempotency-Key and refuses a different body', function (): void {
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(50_000, 3));
    [$cart, $token] = guestCart($this, $variant);
    $key = TestCommerce::idempotencyKey();
    $body = orderBody($cart, 75_000);

    $first = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => $key])
        ->postJson($this->api.'/checkout/orders', $body)->assertCreated();

    $again = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => $key])
        ->postJson($this->api.'/checkout/orders', $body)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data'))->toBe($first->json('data'));

    $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => $key])
        ->postJson($this->api.'/checkout/orders', [...$body, 'email' => 'someone-else@example.test'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    $this->brand->run(function () use ($variant, $first): void {
        expect(Order::query()->where('ulid', $first->json('data.order_id'))->count())->toBe(1)
            ->and(TestCommerce::stock($variant)['on_hand'])->toBe(2);
    });
});

it('requires an Idempotency-Key to place an order', function (): void {
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(50_000, 3));
    [$cart, $token] = guestCart($this, $variant);

    $this->withHeader('X-Cart-Token', $token)
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 75_000))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a stale total with 409 price_changed and writes nothing', function (): void {
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(50_000, 3));
    [$cart, $token] = guestCart($this, $variant);

    $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 70_000))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'price_changed')
        ->assertJsonPath('error.details.total.amount', 75_000);

    $this->brand->run(function () use ($variant, $cart): void {
        expect(TestCommerce::stock($variant))->toBe(['on_hand' => 3, 'reserved' => 0])
            ->and(Cart::query()->where('ulid', $cart)->value('status'))->toBe('active');
    });
});

it('refuses with 409 stock_unavailable when stock ran out after the cart was filled, leaving nothing behind', function (): void {
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(50_000, 2));
    [$cartA, $tokenA] = guestCart($this, $variant, 2);
    [$cartB, $tokenB] = guestCart($this, $variant, 2);

    $this->withHeaders(['X-Cart-Token' => $tokenA, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cartA, 125_000))->assertCreated();

    $this->withHeaders(['X-Cart-Token' => $tokenB, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cartB, 125_000))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'stock_unavailable');

    $this->brand->run(function () use ($variant, $cartB): void {
        expect(TestCommerce::stock($variant))->toBe(['on_hand' => 0, 'reserved' => 0])
            ->and(StockReservation::query()->where('variant_id', $variant->id)->count())->toBe(1)
            ->and(Cart::query()->where('ulid', $cartB)->value('status'))->toBe('active');
    });
});

it('refuses COD-only mistakes and unknown payment methods', function (): void {
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(50_000, 2));
    [$cart, $token] = guestCart($this, $variant);

    $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 75_000, ['payment_method' => 'bitcoin']))
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('payment_method', 'error.details');
});

it('applies a coupon, records its use, and gives it back on cancellation', function (): void {
    $code = 'SAVE'.strtoupper(Str::random(6));
    $variant = $this->brand->run(function () use ($code) {
        app(SaveCoupon::class)->handle(null, new CouponData(code: $code, discountType: 'percentage', value: 1000, usageLimit: 1));

        return TestCommerce::stockedVariant(100_000, 5);
    });

    [$cart, $token] = guestCart($this, $variant, 2);
    $this->withHeader('X-Cart-Token', $token)->postJson($this->api.'/carts/'.$cart.'/coupon', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.totals.discount.amount', 20_000)
        ->assertJsonPath('data.coupon.applied', true);

    // Rs 2,000 − 10% = Rs 1,800 goods + Rs 250 shipping.
    $placed = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 205_000))
        ->assertCreated();

    // The single use is taken: a second cart cannot redeem it.
    [$cart2, $token2] = guestCart($this, $variant);
    $this->withHeader('X-Cart-Token', $token2)->postJson($this->api.'/carts/'.$cart2.'/coupon', ['code' => $code])
        ->assertUnprocessable();

    $this->brand->run(function () use ($placed, $code): void {
        $orderId = (string) $placed->json('data.order_id');
        $order = Order::query()->where('ulid', $orderId)->firstOrFail();

        expect($order->discount->amount)->toBe(20_000)
            ->and($order->items->sum(fn ($item): int => $item->total->amount) + $order->shipping->amount)->toBe($order->total->amount);

        app(Modules\Orders\Actions\CancelOrder::class)->handle($orderId, 'Customer changed their mind');

        expect(Modules\Pricing\Models\Coupon::query()->where('code', $code)->value('used_count'))->toBe(0);
    });
});

it('takes a manual transfer order through receipt upload and staff verification', function (): void {
    Mail::fake();
    $variant = $this->brand->run(function () {
        TestCommerce::enableTransfers();

        return TestCommerce::stockedVariant(300_000, 4);
    });
    [$cart, $token] = guestCart($this, $variant);

    // Rs 3,000 + Rs 250 shipping.
    $placed = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 325_000, ['payment_method' => 'manual_transfer']))
        ->assertCreated();

    $orderId = (string) $placed->json('data.order_id');
    $accessToken = (string) $placed->json('data.access_token');

    // Prepaid: the stock is held, not yet sold.
    $this->brand->run(fn () => expect(TestCommerce::stock($variant))->toBe(['on_hand' => 4, 'reserved' => 1]));

    $this->withHeader('X-Order-Token', $accessToken)->getJson($this->api.'/orders/'.$orderId)
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.payment.can_upload_proof', true)
        ->assertJsonPath('data.payment.instructions', "Meezan Bank\nAccount title: Test Brand\nIBAN: PK00TEST0000000000000000");

    $receipt = UploadedFile::fake()->image('receipt.png', 400, 600);
    $this->withHeader('X-Order-Token', $accessToken)
        ->post($this->api.'/orders/'.$orderId.'/payment-proof', ['receipt' => $receipt, 'reference' => 'TRX-123'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.payment.status', 'awaiting_verification');

    $this->brand->run(function () use ($orderId, $variant): void {
        app(Payments::class)->reviewTransfer($orderId, true, 'BANK-REF-1', null, null);

        $order = Order::query()->where('ulid', $orderId)->firstOrFail();

        expect($order->status::getMorphClass())->toBe('confirmed')
            ->and($order->payment_status)->toBe('paid')
            ->and(TestCommerce::stock($variant))->toBe(['on_hand' => 3, 'reserved' => 0]);
    });

    Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => $mail->order['id'] === $orderId && str_contains($mail->subjectLine, 'confirmed'));
});

it('cancels a transfer order whose receipt is rejected and frees its stock', function (): void {
    $variant = $this->brand->run(function () {
        TestCommerce::enableTransfers();

        return TestCommerce::stockedVariant(100_000, 2);
    });
    [$cart, $token] = guestCart($this, $variant);

    $orderId = (string) $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => TestCommerce::idempotencyKey()])
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 125_000, ['payment_method' => 'manual_transfer']))
        ->assertCreated()
        ->json('data.order_id');

    $this->brand->run(function () use ($orderId, $variant): void {
        app(Payments::class)->reviewTransfer($orderId, false, null, 'No money received', null);

        $order = Order::query()->where('ulid', $orderId)->firstOrFail();

        expect($order->status::getMorphClass())->toBe('cancelled')
            ->and($order->payment_status)->toBe('voided')
            ->and(TestCommerce::stock($variant))->toBe(['on_hand' => 2, 'reserved' => 0]);
    });
});

it('lists a signed-in customer\'s orders and hides them from other customers', function (): void {
    $variant = $this->brand->run(fn () => TestCommerce::stockedVariant(50_000, 3));
    $email = 'cust-'.Str::lower(Str::random(8)).'@example.test';

    $this->postJson($this->api.'/auth/register', ['email' => $email, 'password' => 'Sufficiently-long-9', 'first_name' => 'Sana'])->assertCreated();
    $bearer = (string) $this->postJson($this->api.'/auth/login', ['email' => $email, 'password' => 'Sufficiently-long-9'])->json('data.token');

    $cart = (string) $this->withToken($bearer)->postJson($this->api.'/carts')->assertCreated()->json('data.id');
    $this->withToken($bearer)->postJson($this->api.'/carts/'.$cart.'/items', ['variant_id' => $variant->ulid, 'quantity' => 1])->assertOk();

    $orderId = (string) $this->withToken($bearer)->withHeader('Idempotency-Key', TestCommerce::idempotencyKey())
        ->postJson($this->api.'/checkout/orders', orderBody($cart, 75_000, ['email' => null]))
        ->assertCreated()
        ->json('data.order_id');

    $this->withToken($bearer)->getJson($this->api.'/account/orders')
        ->assertOk()
        ->assertJsonPath('data.0.id', $orderId)
        ->assertJsonPath('data.0.item_count', 1);

    $this->withToken($bearer)->getJson($this->api.'/account/orders/'.$orderId)->assertOk()->assertJsonPath('data.email', $email);

    $otherEmail = 'cust-'.Str::lower(Str::random(8)).'@example.test';
    $this->app['auth']->forgetGuards();
    $this->postJson($this->api.'/auth/register', ['email' => $otherEmail, 'password' => 'Sufficiently-long-9', 'first_name' => 'Bilal'])->assertCreated();
    $other = (string) $this->postJson($this->api.'/auth/login', ['email' => $otherEmail, 'password' => 'Sufficiently-long-9'])->json('data.token');
    $this->app['auth']->forgetGuards();

    $this->withToken($other)->getJson($this->api.'/account/orders/'.$orderId)->assertNotFound();
    $this->withToken($other)->getJson($this->api.'/account/orders')->assertOk()->assertJsonCount(0, 'data');
});
