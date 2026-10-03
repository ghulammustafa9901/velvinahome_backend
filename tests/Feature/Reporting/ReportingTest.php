<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Cart\Actions\CreateCart;
use Modules\Cart\Actions\SetCartItem;
use Modules\Catalog\Models\ProductVariant;
use Modules\Checkout\Actions\PlaceOrder;
use Modules\Checkout\Data\PlaceOrderInput;
use Modules\Customers\Data\AddressData;
use Modules\Orders\Actions\CancelOrder;
use Modules\Orders\Actions\ConfirmCodOrder;
use Modules\Orders\Actions\CreateShipment;
use Modules\Orders\Actions\RefundOrder;
use Modules\Orders\Actions\UpdateShipment;
use Modules\Orders\Models\Order;
use Modules\Reporting\Actions\RefreshReports;
use Modules\Reporting\Filament\Widgets\SalesChartWidget;
use Modules\Reporting\Filament\Widgets\SalesOverviewWidget;
use Modules\Reporting\Filament\Widgets\TopProductsWidget;
use Modules\Reporting\Models\ProductDayReport;
use Modules\Reporting\Models\ReportCursor;
use Modules\Reporting\Models\SalesDayReport;
use Modules\Reporting\Queries\ReportDiscrepancies;
use Modules\Reporting\Queries\SalesReport;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\ValueObjects\Money;
use Tests\Support\TestCommerce;
use Tests\Support\TestStore;

/*
| Phase 6 exit criterion: reports match raw data. Aggregates are rebuilt per brand-local day
| from orders and refunds, incrementally, and a verifier proves they agree with the records.
*/

beforeEach(function (): void {
    Mail::fake();
    // The test store is shared across tests and runs: start each test from a full rebuild.
    ReportCursor::query()->delete();
});

function reportOrder(ProductVariant $variant, int $quantity): Order
{
    $cart = app(CreateCart::class)->handle(null);
    app(SetCartItem::class)->handle($cart['cart'], $variant->ulid, $quantity);
    $goods = $variant->price->amount * $quantity;

    $result = app(PlaceOrder::class)->handle(new PlaceOrderInput(
        cartUlid: $cart['cart']->ulid,
        cartToken: $cart['token'],
        customerId: null,
        email: 'report-'.Str::lower(Str::random(6)).'@example.test',
        shippingAddress: AddressData::from(TestCommerce::address()),
        billingAddress: null,
        shippingMethodCode: 'standard',
        paymentMethodCode: 'cod',
        note: null,
        expectedTotal: $goods + ($goods >= 500_000 ? 0 : 25_000),
        idempotencyKey: TestCommerce::idempotencyKey(),
        ipAddress: null,
    ));

    return Order::query()->where('ulid', $result['order_id'])->firstOrFail();
}

/**
 * @return array<string, int>
 */
function todayRow(): array
{
    $today = Carbon::now(app(StoreSettings::class)->string('store.timezone', 'Asia/Karachi'))->format('Y-m-d');
    $row = SalesDayReport::query()->whereDate('date', $today)->where('currency', 'PKR')->first();

    return $row === null ? array_fill_keys(SalesDayReport::COUNTERS, 0) : $row->only(SalesDayReport::COUNTERS);
}

it('matches the raw orders and refunds of the day after a refresh', function (): void {
    app(RefreshReports::class)->handle();
    $before = todayRow();

    $variant = TestCommerce::stockedVariant(100_000, 10);
    $kept = reportOrder($variant, 2);        // 225,000 with shipping
    $cancelled = reportOrder($variant, 1);   // 125,000 with shipping

    app(ConfirmCodOrder::class)->handle($kept->ulid, 'confirmed');
    $shipment = app(CreateShipment::class)->handle($kept->ulid, null, null, null);
    app(UpdateShipment::class)->handle($shipment->ulid, 'delivered');
    app(RefundOrder::class)->handle($kept->ulid, Money::of(50_000, 'PKR'), 'Goodwill', TestCommerce::idempotencyKey());
    app(CancelOrder::class)->handle($cancelled->ulid, 'Changed mind');

    app(RefreshReports::class)->handle();
    $after = todayRow();
    $delta = array_map(fn (string $key): int => $after[$key] - $before[$key], array_combine(SalesDayReport::COUNTERS, SalesDayReport::COUNTERS));

    expect($delta)->toMatchArray([
        'orders_placed' => 2,
        'orders_cancelled' => 1,
        'units' => 2,
        'subtotal_amount' => 200_000,
        'shipping_amount' => 25_000,
        'total_amount' => 225_000,
        'placed_total_amount' => 350_000,
        'refunded_amount' => 50_000,
        'refunds' => 1,
        'cod_orders' => 2,
        'cod_confirmed' => 1,
    ]);

    $product = ProductDayReport::query()->where('product_ulid', $variant->product->ulid)->firstOrFail();
    expect($product->only(['units', 'revenue_amount', 'orders']))->toBe(['units' => 2, 'revenue_amount' => 200_000, 'orders' => 1]);

    $report = app(SalesReport::class);
    expect(app(ReportDiscrepancies::class)->between($report->today(), $report->today()))->toBe([]);
});

it('rebuilds only the days that changed, and catches later changes', function (): void {
    app(RefreshReports::class)->handle();

    // Nothing changed after the watermark (placed beyond the look-back window): nothing is
    // rebuilt. Only the watermark moves; no record gets a future timestamp.
    ReportCursor::query()->update(['value' => Carbon::now()->addMinutes(15)]);
    expect(app(RefreshReports::class)->handle())->toBe([]);

    $order = reportOrder(TestCommerce::stockedVariant(90_000, 5), 1);
    $today = app(SalesReport::class)->today();

    // Other tests share this brand, so other days may be rebuilt too; today must be.
    expect(app(RefreshReports::class)->handle())->toContain($today);

    app(CancelOrder::class)->handle($order->ulid, 'Duplicate');
    expect(app(RefreshReports::class)->handle())->toContain($today)
        ->and(app(ReportDiscrepancies::class)->between($today, $today))->toBe([]);
});

it('counts an order on the brand-local day it was placed', function (): void {
    $order = reportOrder(TestCommerce::stockedVariant(80_000, 5), 1);

    // 21:30 UTC on 1 March is already 2 March in Karachi (UTC+5). A past year with no orders
    // around those days keeps them to this test in the shared test store.
    do {
        $year = random_int(1901, 1999);
    } while (Order::query()->whereBetween('placed_at', ["{$year}-02-28", "{$year}-03-04"])->exists());
    $order->forceFill(['placed_at' => Carbon::parse("{$year}-03-01 21:30:00", 'UTC')])->save();

    app(RefreshReports::class)->handle();

    expect(SalesDayReport::query()->whereDate('date', "{$year}-03-02")->value('orders_placed'))->toBe(1)
        ->and(SalesDayReport::query()->whereDate('date', "{$year}-03-01")->exists())->toBeFalse()
        ->and(app(ReportDiscrepancies::class)->between("{$year}-03-01", "{$year}-03-02"))->toBe([]);
});

it('reports a tampered or stale aggregate, and the verify command fails on it', function (): void {
    reportOrder(TestCommerce::stockedVariant(70_000, 5), 1);
    app(RefreshReports::class)->handle();
    $today = app(SalesReport::class)->today();

    SalesDayReport::query()->whereDate('date', $today)->where('currency', 'PKR')->increment('total_amount', 1);

    expect(app(ReportDiscrepancies::class)->between($today, $today))
        ->toHaveCount(1)
        ->and(app(ReportDiscrepancies::class)->between($today, $today)[0])->toContain('total_amount');

    $this->artisan('reports:verify', ['--from' => $today, '--to' => $today])->assertFailed();
    $this->artisan('reports:refresh', ['--full' => true])->assertSuccessful();
    $this->artisan('reports:verify', ['--from' => $today, '--to' => $today])->assertSuccessful();
});

it('shows the sales report and dashboard to staff with reports access only', function (): void {
    $admin = 'http://localhost';

    $this->actingAs(TestStore::staff(role: 'Accountant'), 'staff')->get($admin.'/reports/sales')->assertOk()->assertSee('Sales report');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(TestStore::staff(role: 'Owner'), 'staff');

    Livewire::test(SalesOverviewWidget::class)->assertSee('Sales, last 30 days');
    Livewire::test(TopProductsWidget::class)->assertSee('Best sellers, last 30 days');
    Livewire::test(SalesChartWidget::class)->assertOk();
    expect(SalesChartWidget::canView())->toBeTrue();

    $this->actingAs(TestStore::staff(role: 'Order Handler'), 'staff');
    expect(SalesOverviewWidget::canView())->toBeFalse()->and(TopProductsWidget::canView())->toBeFalse();
});

it('keeps the sales report from staff without reports access', function (): void {
    $this->actingAs(TestStore::staff(role: 'Order Handler'), 'staff')
        ->get('http://localhost/reports/sales')->assertForbidden();
});
