<?php

declare(strict_types=1);

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
use Modules\Integrations\Actions\PublishPendingOutboxMessages;
use Modules\Integrations\Models\OutboxMessage;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Notifications\Handlers\OrderShippedEmail;
use Modules\Notifications\Mail\OrderMail;
use Modules\Orders\Actions\AdvanceOrder;
use Modules\Orders\Actions\CancelOrder;
use Modules\Orders\Actions\CancelUnpaidOrders;
use Modules\Orders\Actions\ConfirmCodOrder;
use Modules\Orders\Actions\CreateShipment;
use Modules\Orders\Actions\RecordReturn;
use Modules\Orders\Actions\RefundOrder;
use Modules\Orders\Actions\UpdateShipment;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderStateTransition;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentTransaction;
use Modules\Support\ValueObjects\Money;
use Tests\Support\TestCommerce;
use Tests\Support\TestPlatform;

/*
| Staff can confirm, fulfil, cancel and refund orders; the state machine refuses illegal
| moves; every cancellation path restocks (ADR-0010); money never goes out twice.
*/

beforeEach(function (): void {
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));
    Mail::fake();
});

/**
 * Place an order directly through the Checkout Action (no HTTP), for lifecycle tests.
 */
function placeOrder(ProductVariant $variant, int $quantity = 2, string $method = 'cod'): Order
{
    $cart = app(CreateCart::class)->handle(null);
    app(SetCartItem::class)->handle($cart['cart'], $variant->ulid, $quantity);

    // Goods + flat Rs 250 shipping (below the free-shipping threshold in these tests).
    $expected = $variant->price->amount * $quantity + ($variant->price->amount * $quantity >= 500_000 ? 0 : 25_000);

    $result = app(PlaceOrder::class)->handle(new PlaceOrderInput(
        cartUlid: $cart['cart']->ulid,
        cartToken: $cart['token'],
        customerId: null,
        email: 'buyer-'.Str::lower(Str::random(6)).'@example.test',
        shippingAddress: AddressData::from(TestCommerce::address()),
        billingAddress: null,
        shippingMethodCode: 'standard',
        paymentMethodCode: $method,
        note: null,
        expectedTotal: $expected,
        idempotencyKey: TestCommerce::idempotencyKey(),
        ipAddress: '127.0.0.1',
    ));

    return Order::query()->where('ulid', $result['order_id'])->firstOrFail();
}

function statusOf(Order $order): string
{
    return $order->refresh()->status::getMorphClass();
}

it('runs a COD order from confirmation call to completion, collecting the cash on delivery', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $order = placeOrder($variant);

    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed');
    app(AdvanceOrder::class)->handle($order->ulid, 'processing');
    $shipment = app(CreateShipment::class)->handle($order->ulid, null, 'Leopards', 'LP-123456');

    expect(statusOf($order))->toBe('fulfilled')
        ->and($order->items->first()?->quantity_fulfilled)->toBe(2);

    app(UpdateShipment::class)->handle($shipment->ulid, 'delivered');

    expect(statusOf($order))->toBe('delivered')
        ->and($order->payment_status)->toBe('paid')
        ->and(Payment::query()->where('order_ulid', $order->ulid)->value('paid_amount'))->toBe(225_000)
        ->and(PaymentTransaction::query()->whereIn('payment_id', Payment::query()->where('order_ulid', $order->ulid)->select('id'))->where('type', 'capture')->count())->toBe(1);

    app(AdvanceOrder::class)->handle($order->ulid, 'completed');

    expect(statusOf($order))->toBe('completed')
        ->and(OrderStateTransition::query()->where('order_id', $order->id)->pluck('to_status')->all())
        ->toBe(['awaiting_confirmation', 'confirmed', 'processing', 'fulfilled', 'delivered', 'completed']);

    Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => str_contains($mail->intro, 'LP-123456'));
});

it('refuses transitions the state machine does not allow', function (): void {
    $order = placeOrder(TestCommerce::stockedVariant(100_000, 5));

    expect(fn () => app(AdvanceOrder::class)->handle($order->ulid, 'completed'))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateShipment::class)->handle($order->ulid, null, null, null))->toThrow(ValidationException::class);

    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed');
    $shipment = app(CreateShipment::class)->handle($order->ulid, null, null, null);

    // Shipped orders cannot be cancelled outright; they come back as RTO or a return.
    expect(fn () => app(CancelOrder::class)->handle($order->ulid, 'Too late'))->toThrow(ValidationException::class)
        ->and(statusOf($order))->toBe('fulfilled');

    app(UpdateShipment::class)->handle($shipment->ulid, 'delivered');
    expect(fn () => app(UpdateShipment::class)->handle($shipment->ulid, 'returned'))->toThrow(ValidationException::class);
});

it('ships in parts and delivers only when every parcel has arrived', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $order = placeOrder($variant, 3);
    $item = $order->items->firstOrFail();

    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed');
    $first = app(CreateShipment::class)->handle($order->ulid, [$item->ulid => 1], 'TCS', 'T-1');
    expect(statusOf($order))->toBe('partially_fulfilled');

    expect(fn () => app(CreateShipment::class)->handle($order->ulid, [$item->ulid => 3], 'TCS', 'T-2'))->toThrow(ValidationException::class);

    $second = app(CreateShipment::class)->handle($order->ulid, [$item->ulid => 2], 'TCS', 'T-2');
    expect(statusOf($order))->toBe('fulfilled');

    app(UpdateShipment::class)->handle($first->ulid, 'delivered');
    expect(statusOf($order))->toBe('fulfilled');

    app(UpdateShipment::class)->handle($second->ulid, 'delivered');
    expect(statusOf($order))->toBe('delivered');
});

it('puts stock back when a confirmed COD order is cancelled', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $order = placeOrder($variant);
    expect(TestCommerce::stock($variant)['on_hand'])->toBe(3);

    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed');
    app(CancelOrder::class)->handle($order->ulid, 'Customer cancelled by phone');

    expect(statusOf($order))->toBe('cancelled')
        ->and($order->payment_status)->toBe('voided')
        ->and(TestCommerce::stock($variant))->toBe(['on_hand' => 5, 'reserved' => 0])
        ->and(InventoryMovement::query()->where('variant_id', $variant->id)->where('type', 'return')->value('reason'))->toContain('cancelled');
});

it('keeps an unreachable COD order waiting and cancels a fake one', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $order = placeOrder($variant);

    app(ConfirmCodOrder::class)->handle($order->ulid, 'unreachable');
    app(ConfirmCodOrder::class)->handle($order->ulid, 'unreachable', 'Phone off');

    expect(statusOf($order))->toBe('awaiting_confirmation')
        ->and($order->cod_attempts)->toBe(2)
        ->and($order->cod_confirmation)->toBe('unreachable');

    app(ConfirmCodOrder::class)->handle($order->ulid, 'fake');

    expect(statusOf($order))->toBe('cancelled')
        ->and($order->cancel_reason)->toStartWith('Fake order')
        ->and(TestCommerce::stock($variant)['on_hand'])->toBe(5);
});

it('restocks a parcel returned to origin and closes the unpaid COD payment', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $order = placeOrder($variant);

    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed');
    $shipment = app(CreateShipment::class)->handle($order->ulid, null, 'PostEx', 'PX-9');
    app(UpdateShipment::class)->handle($shipment->ulid, 'returned', 'Customer refused parcel');

    expect(statusOf($order))->toBe('returned')
        ->and($order->payment_status)->toBe('voided')
        ->and(TestCommerce::stock($variant)['on_hand'])->toBe(5)
        ->and($order->items->first()?->quantity_returned)->toBe(2);
});

it('records a partial customer return and refunds no more than was paid, once per key', function (): void {
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $order = placeOrder($variant);
    $item = $order->items->firstOrFail();

    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed');
    $shipment = app(CreateShipment::class)->handle($order->ulid, null, null, null);
    app(UpdateShipment::class)->handle($shipment->ulid, 'delivered');

    app(RecordReturn::class)->handle($order->ulid, [$item->ulid => 1], 'Wrong size', restock: true);

    expect(statusOf($order))->toBe('delivered')
        ->and(TestCommerce::stock($variant)['on_hand'])->toBe(4);

    expect(fn () => app(RecordReturn::class)->handle($order->ulid, [$item->ulid => 2], 'Again', restock: true))
        ->toThrow(ValidationException::class);

    expect(fn () => app(RefundOrder::class)->handle($order->ulid, Money::of(300_000, 'PKR'), 'Too much', TestCommerce::idempotencyKey()))
        ->toThrow(ValidationException::class);

    $key = TestCommerce::idempotencyKey();
    app(RefundOrder::class)->handle($order->ulid, Money::of(100_000, 'PKR'), 'Returned one item', $key);
    app(RefundOrder::class)->handle($order->ulid, Money::of(100_000, 'PKR'), 'Returned one item', $key);

    expect($order->refresh()->payment_status)->toBe('partially_refunded')
        ->and(Payment::query()->where('order_ulid', $order->ulid)->value('refunded_amount'))->toBe(100_000);

    app(RecordReturn::class)->handle($order->ulid, [$item->ulid => 1], 'Damaged', restock: false);

    expect(statusOf($order))->toBe('returned')
        ->and(TestCommerce::stock($variant)['on_hand'])->toBe(4);
});

it('holds staff to their permissions', function (): void {
    $order = placeOrder(TestCommerce::stockedVariant(100_000, 5));
    $staff = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: null);

    expect(fn () => app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed', null, $staff))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelOrder::class)->handle($order->ulid, 'x', $staff))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RefundOrder::class)->handle($order->ulid, Money::of(1, 'PKR'), 'x', TestCommerce::idempotencyKey(), $staff))->toThrow(AuthorizationException::class);

    $manager = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B));
    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed', null, $manager);

    expect(OrderStateTransition::query()->where('order_id', $order->id)->where('to_status', 'confirmed')->value('actor_ulid'))->toBe($manager->ulid);
});

it('cancels transfer orders left unpaid past the payment window, but not those still within it', function (): void {
    TestCommerce::enableTransfers();
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $stale = placeOrder($variant, 1, 'manual_transfer');
    $fresh = placeOrder($variant, 1, 'manual_transfer');

    $stale->forceFill(['placed_at' => now()->subHours(CancelUnpaidOrders::DEFAULT_WINDOW_HOURS + 1)])->save();

    expect(app(CancelUnpaidOrders::class)->handle())->toBeGreaterThanOrEqual(1)
        ->and(statusOf($stale))->toBe('cancelled')
        ->and(statusOf($fresh))->toBe('pending_payment')
        ->and(TestCommerce::stock($variant))->toBe(['on_hand' => 5, 'reserved' => 1]);
});

it('keeps the timeline append-only for the application role', function (): void {
    $order = placeOrder(TestCommerce::stockedVariant(100_000, 5));

    expect(fn () => OrderStateTransition::query()->where('order_id', $order->id)->update(['to_status' => 'completed']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('sends each order email once even when the outbox message is delivered again', function (): void {
    $order = placeOrder(TestCommerce::stockedVariant(100_000, 5));
    app(ConfirmCodOrder::class)->handle($order->ulid, 'confirmed');
    $shipment = app(CreateShipment::class)->handle($order->ulid, null, 'M&P', 'MP-1');

    $message = OutboxMessage::query()->where('event_type', 'order.shipped')->where('payload->shipment_ulid', $shipment->ulid)->firstOrFail();
    Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => str_contains($mail->intro, 'MP-1'));

    // At-least-once delivery: the handler sees the same message again.
    app(OrderShippedEmail::class)->handle($message->ulid, $message->payload);
    app(PublishPendingOutboxMessages::class)->handle();

    Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => str_contains($mail->intro, 'MP-1'), 1);
});
