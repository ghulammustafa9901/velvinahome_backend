<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Cart\Actions\CreateCart;
use Modules\Cart\Actions\SetCartItem;
use Modules\Checkout\Actions\PlaceOrder;
use Modules\Checkout\Data\PlaceOrderInput;
use Modules\Customers\Data\AddressData;
use Modules\Orders\Filament\Resources\Orders\ViewOrderPage;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use Modules\Pricing\Filament\Resources\Coupons\ListCouponsPage;
use Modules\Pricing\Models\Coupon;
use Tests\Support\TestCommerce;
use Tests\Support\TestPlatform;

/*
| Staff can confirm, fulfil, cancel and refund orders from the admin (phase 3 exit criteria),
| and the screens follow permissions.
*/

beforeEach(function (): void {
    Mail::fake();
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_B);
    $this->owner = TestPlatform::staff($this->brand, role: 'Owner');
    $this->adminUrl = 'http://'.TestPlatform::adminHost(TestPlatform::BRAND_B);
});

function adminAs(object $test, $staff): void
{
    tenancy()->initialize($test->brand);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $test->actingAs($staff, 'staff');
}

function adminOrder(): Order
{
    $variant = TestCommerce::stockedVariant(100_000, 5);
    $cart = app(CreateCart::class)->handle(null);
    app(SetCartItem::class)->handle($cart['cart'], $variant->ulid, 2);

    $result = app(PlaceOrder::class)->handle(new PlaceOrderInput(
        cartUlid: $cart['cart']->ulid,
        cartToken: $cart['token'],
        customerId: null,
        email: 'buyer-'.Str::lower(Str::random(6)).'@example.test',
        shippingAddress: AddressData::from(TestCommerce::address()),
        billingAddress: null,
        shippingMethodCode: 'standard',
        paymentMethodCode: 'cod',
        note: 'Please call before delivery',
        expectedTotal: 225_000,
        idempotencyKey: TestCommerce::idempotencyKey(),
        ipAddress: null,
    ));

    return Order::query()->where('ulid', $result['order_id'])->firstOrFail();
}

it('renders the commerce screens for an owner', function (string $path): void {
    $this->actingAs($this->owner, 'staff')->get($this->adminUrl.$path)->assertOk();
})->with(['/orders', '/coupons', '/shipping-methods', '/tax-rates', '/payment-methods', '/notification-templates']);

it('shows an order page with its items and timeline', function (): void {
    $order = $this->brand->run(fn () => adminOrder());

    // Admin URLs carry the ULID, never the internal id.
    $this->actingAs($this->owner, 'staff')->get($this->adminUrl.'/orders/'.$order->ulid)
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee('Please call before delivery')
        ->assertSee('Awaiting confirmation');

    $this->actingAs($this->owner, 'staff')->get($this->adminUrl.'/orders/'.$order->getKey())->assertNotFound();
});

it('takes a COD order from confirmation call to delivery and a refund in the admin', function (): void {
    adminAs($this, $this->owner);
    $order = adminOrder();

    Livewire::test(ViewOrderPage::class, ['record' => $order->getRouteKey()])
        ->callAction('confirmCod', data: ['outcome' => 'confirmed'])
        ->assertHasNoActionErrors()
        ->callAction('ship', data: ['courier' => 'Leopards', 'tracking_number' => 'LP-77', 'lines' => [$order->items->firstOrFail()->ulid => 2]])
        ->assertHasNoActionErrors();

    $shipment = $order->refresh()->shipments->firstOrFail();

    Livewire::test(ViewOrderPage::class, ['record' => $order->getRouteKey()])
        ->callAction('shipmentOutcome', data: ['shipment' => $shipment->ulid, 'outcome' => 'delivered'])
        ->assertHasNoActionErrors()
        ->callAction('refund', data: ['amount' => '1,000', 'reason' => 'Goodwill'])
        ->assertHasNoActionErrors();

    expect($order->refresh()->status::getMorphClass())->toBe('delivered')
        ->and($order->payment_status)->toBe('partially_refunded')
        ->and(Payment::query()->where('order_ulid', $order->ulid)->value('refunded_amount'))->toBe(100_000);
});

it('hides the refund and COD buttons from staff without those permissions', function (): void {
    $inventory = TestPlatform::staff($this->brand, role: 'Inventory Staff');
    adminAs($this, $inventory);
    $order = adminOrder();

    Livewire::test(ViewOrderPage::class, ['record' => $order->getRouteKey()])
        ->assertActionHidden('confirmCod')
        ->assertActionHidden('cancel')
        ->assertActionHidden('refund');
});

it('keeps an order handler out of coupons and settings screens', function (): void {
    $handler = TestPlatform::staff($this->brand, role: 'Order Handler');

    $this->actingAs($handler, 'staff')->get($this->adminUrl.'/orders')->assertOk();
    $this->actingAs($handler, 'staff')->get($this->adminUrl.'/coupons')->assertForbidden();
    $this->actingAs($handler, 'staff')->get($this->adminUrl.'/payment-methods')->assertForbidden();
});

it('creates a coupon with a percentage typed as percent', function (): void {
    adminAs($this, $this->owner);
    $code = 'ADM'.strtoupper(Str::random(6));

    Livewire::test(ListCouponsPage::class)
        ->callAction('create', data: ['code' => $code, 'discount_type' => 'percentage', 'value' => '12.5', 'minimum_spend' => '2,000', 'is_active' => true])
        ->assertHasNoActionErrors();

    $coupon = Coupon::query()->where('code', $code)->firstOrFail();

    expect($coupon->value)->toBe(1250)
        ->and($coupon->minimum_spend_amount)->toBe(200_000);
});
