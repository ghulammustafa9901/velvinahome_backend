<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Actions\CreateCart;
use Modules\Cart\Actions\SetCartItem;
use Modules\Checkout\Actions\PlaceOrder;
use Modules\Checkout\Data\PlaceOrderInput;
use Modules\Customers\Data\AddressData;
use Modules\Orders\Actions\ConfirmCodOrder;
use Modules\Orders\Actions\CreateShipment;
use Modules\Orders\Actions\SyncShipmentTracking;
use Modules\Orders\Contracts\OrderBook;
use Modules\Orders\Models\Order;
use Modules\Shipping\Actions\SaveCourier;
use Modules\Shipping\Contracts\CourierAdapter;
use Modules\Shipping\Contracts\Couriers;
use Modules\Shipping\Data\CourierTracking;
use Modules\Shipping\Models\Courier;
use Modules\Shipping\Support\CourierAdapters;
use Tests\Support\TestCommerce;
use Tests\Support\TestPlatform;

/*
| Courier framework (owner: no courier account yet): tracking links from staff-entered
| templates, and an adapter contract whose tracking updates orders like staff would.
*/

final class FakeTrackingCourier implements CourierAdapter
{
    /** @var array<string, string> */
    public static array $statuses = [];

    public function key(): string
    {
        return 'fake-tracking';
    }

    public function label(): string
    {
        return 'Fake tracking (tests)';
    }

    public function tracks(): bool
    {
        return true;
    }

    public function track(string $trackingNumber, array $credentials): CourierTracking
    {
        return new CourierTracking(self::$statuses[$trackingNumber] ?? 'unknown');
    }
}

beforeEach(function (): void {
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));
    Mail::fake();
});

function shippedOrder(string $courier, string $tracking): Order
{
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $cart = app(CreateCart::class)->handle(null);
    app(SetCartItem::class)->handle($cart['cart'], $variant->ulid, 1);
    $placed = app(PlaceOrder::class)->handle(new PlaceOrderInput(
        cartUlid: $cart['cart']->ulid, cartToken: $cart['token'], customerId: null, email: 'courier-'.Str::lower(Str::random(6)).'@example.test',
        shippingAddress: AddressData::from(TestCommerce::address()), billingAddress: null, shippingMethodCode: 'standard',
        paymentMethodCode: 'cod', note: null, expectedTotal: 125_000, idempotencyKey: TestCommerce::idempotencyKey(), ipAddress: null,
    ));

    app(ConfirmCodOrder::class)->handle($placed['order_id'], 'confirmed');
    app(CreateShipment::class)->handle($placed['order_id'], null, $courier, $tracking);

    return Order::query()->where('ulid', $placed['order_id'])->firstOrFail();
}

it('gives customers a tracking link built from the courier template', function (): void {
    $name = 'Swift '.Str::random(5);
    app(SaveCourier::class)->handle(null, $name, 'manual', 'https://track.example/parcel?cn={tracking}');
    $order = shippedOrder($name, 'LP 12/34');

    $shipment = app(OrderBook::class)->describe($order->ulid)['shipments'][0];

    expect($shipment['tracking_url'])->toBe('https://track.example/parcel?cn=LP%2012%2F34')
        ->and(app(Couriers::class)->trackingUrl('Nobody Express', 'X1'))->toBeNull();
});

it('only accepts https tracking templates with a {tracking} placeholder', function (): void {
    foreach (['http://track.example/{tracking}', 'https://track.example/no-placeholder', 'javascript:alert(1)//{tracking}'] as $template) {
        expect(fn () => app(SaveCourier::class)->handle(null, 'Bad '.Str::random(5), 'manual', $template))->toThrow(ValidationException::class);
    }

    expect(fn () => app(SaveCourier::class)->handle(null, 'Unknown '.Str::random(5), 'no-such-adapter'))->toThrow(ValidationException::class);
});

it('records deliveries and returns reported by a courier integration', function (): void {
    app(CourierAdapters::class)->register('fake-tracking', FakeTrackingCourier::class);
    $name = 'Tracked '.Str::random(5);
    app(SaveCourier::class)->handle(null, $name, 'fake-tracking', credentials: ['api_key' => 'test-only-key']);

    $delivered = shippedOrder($name, 'DLV-'.Str::random(6));
    $returned = shippedOrder($name, 'RTO-'.Str::random(6));
    $moving = shippedOrder($name, 'MOV-'.Str::random(6));
    $manual = shippedOrder('Leopards', 'MAN-'.Str::random(6));

    FakeTrackingCourier::$statuses = [
        $delivered->shipments->first()?->tracking_number => 'delivered',
        $returned->shipments->first()?->tracking_number => 'returned',
        $moving->shipments->first()?->tracking_number => 'in_transit',
    ];

    app(SyncShipmentTracking::class)->handle();

    expect($delivered->refresh()->status::getMorphClass())->toBe('delivered')
        ->and($returned->refresh()->status::getMorphClass())->toBe('returned')
        ->and($moving->refresh()->status::getMorphClass())->toBe('fulfilled')
        ->and($manual->refresh()->status::getMorphClass())->toBe('fulfilled')
        // Credentials are stored encrypted, never in plain text.
        ->and(Courier::query()->where('name', $name)->toBase()->value('credentials'))->not->toContain('test-only-key');
});

it('shows the couriers screen to staff who manage settings', function (): void {
    tenancy()->end();

    $this->actingAs(TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Owner'), 'staff')
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/couriers')->assertOk()->assertSee('Leopards');
});
