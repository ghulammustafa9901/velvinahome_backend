<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Inventory\Actions\AdjustStock;
use Modules\Inventory\Actions\ApplyStockCount;
use Modules\Inventory\Actions\ExpireReservations;
use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Actions\ReconcileInventory;
use Modules\Inventory\Actions\ReserveStock;
use Modules\Inventory\Actions\SettleReservation;
use Modules\Inventory\Actions\SnapshotInventory;
use Modules\Inventory\Actions\TransferStock;
use Modules\Inventory\Contracts\StockReader;
use Modules\Inventory\Data\StockAdjustmentData;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Events\LowStockReached;
use Modules\Inventory\Exceptions\InsufficientStock;
use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Tests\Support\TestCatalog;

/*
| Spec §6.5: on_hand changes only through Inventory Actions, always with a movement; overselling
| is impossible without backorders; reservations; weighted-average cost; reconciliation.
*/

beforeEach(function (): void {
    $this->product = TestCatalog::simpleProduct();
    $this->variant = $this->product->variants()->firstOrFail();
    $this->main = TestCatalog::defaultLocation();
});

function receive(ProductVariant $variant, string $locationUlid, int $quantity, ?int $unitCost = null): string
{
    return app(ReceiveStock::class)->handle(StockDocumentData::from([
        'locationUlid' => $locationUlid,
        'lines' => [['variantUlid' => $variant->ulid, 'quantity' => $quantity, 'unitCostAmount' => $unitCost]],
    ]));
}

function level(ProductVariant $variant, int $locationId): StockLevel
{
    return StockLevel::query()->where('variant_id', $variant->id)->where('location_id', $locationId)->firstOrFail();
}

it('receives stock with a movement and keeps a weighted-average cost', function (): void {
    receive($this->variant, $this->main->ulid, 10, 100_00);
    receive($this->variant, $this->main->ulid, 10, 200_00);

    expect(level($this->variant, $this->main->id)->on_hand)->toBe(20)
        ->and($this->variant->fresh()?->cost?->amount)->toBe(150_00)
        ->and(InventoryMovement::query()->where('variant_id', $this->variant->id)->pluck('balance_after')->all())->toBe([10, 20]);
});

it('requires a reason for manual adjustments and never lets stock go negative', function (): void {
    receive($this->variant, $this->main->ulid, 2);

    expect(fn () => app(AdjustStock::class)->handle(new StockAdjustmentData($this->variant->ulid, $this->main->ulid, -1, '   ')))
        ->toThrow(InventoryRuleViolation::class)
        ->and(fn () => app(AdjustStock::class)->handle(new StockAdjustmentData($this->variant->ulid, $this->main->ulid, -3, 'breakage in store')))
        ->toThrow(InsufficientStock::class);

    app(AdjustStock::class)->handle(new StockAdjustmentData($this->variant->ulid, $this->main->ulid, -2, 'water damage', 'damage'));

    expect(level($this->variant, $this->main->id)->on_hand)->toBe(0);
});

it('lets a backorderable variant go below zero', function (): void {
    $variant = TestCatalog::simpleProduct(['allowBackorder' => true])->variants()->firstOrFail();

    app(AdjustStock::class)->handle(new StockAdjustmentData($variant->ulid, $this->main->ulid, -3, 'pre-order commitments'));

    expect(level($variant, $this->main->id)->on_hand)->toBe(-3);
});

it('transfers between locations as two legs of one transaction', function (): void {
    $shop = TestCatalog::secondLocation();
    receive($this->variant, $this->main->ulid, 5);

    app(TransferStock::class)->handle(StockDocumentData::from([
        'fromLocationUlid' => $this->main->ulid,
        'locationUlid' => $shop->ulid,
        'lines' => [['variantUlid' => $this->variant->ulid, 'quantity' => 3]],
    ]));

    expect(level($this->variant, $this->main->id)->on_hand)->toBe(2)
        ->and(level($this->variant, $shop->id)->on_hand)->toBe(3);

    // Transferring more than is there fails as a whole: neither leg is written.
    expect(fn () => app(TransferStock::class)->handle(StockDocumentData::from([
        'fromLocationUlid' => $this->main->ulid,
        'locationUlid' => $shop->ulid,
        'lines' => [['variantUlid' => $this->variant->ulid, 'quantity' => 5]],
    ])))->toThrow(InsufficientStock::class);

    expect(level($this->variant, $shop->id)->on_hand)->toBe(3);
});

it('turns a stock count difference into a stock_count movement', function (): void {
    receive($this->variant, $this->main->ulid, 10);

    $result = app(ApplyStockCount::class)->handle(StockDocumentData::from([
        'locationUlid' => $this->main->ulid,
        'lines' => [['variantUlid' => $this->variant->ulid, 'quantity' => 7]],
    ]));

    expect($result['differences'])->toBe(1)
        ->and(level($this->variant, $this->main->id)->on_hand)->toBe(7)
        ->and(InventoryMovement::query()->where('variant_id', $this->variant->id)->where('type', 'stock_count')->value('quantity'))->toBe(-3);
});

it('reserves, commits and releases without ever overselling', function (): void {
    receive($this->variant, $this->main->ulid, 3);
    $reader = app(StockReader::class);

    $first = app(ReserveStock::class)->handle($this->variant->ulid, 2, 'cart', (string) Str::ulid());
    expect($reader->available([$this->variant->id])[$this->variant->id])->toBe(1);

    expect(fn () => app(ReserveStock::class)->handle($this->variant->ulid, 2, 'cart', (string) Str::ulid()))
        ->toThrow(InsufficientStock::class);

    app(SettleReservation::class)->handle($first->ulid, 'commit', (string) Str::ulid());

    $level = level($this->variant, $this->main->id);
    expect($level->on_hand)->toBe(1)
        ->and($level->reserved)->toBe(0)
        ->and(fn () => app(SettleReservation::class)->handle($first->ulid, 'release'))->toThrow(InventoryRuleViolation::class);

    $second = app(ReserveStock::class)->handle($this->variant->ulid, 1, 'cart', (string) Str::ulid());
    app(SettleReservation::class)->handle($second->ulid, 'release');

    expect($reader->available([$this->variant->id])[$this->variant->id])->toBe(1);
});

it('expires only abandoned checkouts, never stock held for an order (ADR-0010)', function (): void {
    receive($this->variant, $this->main->ulid, 2);

    $cart = app(ReserveStock::class)->handle($this->variant->ulid, 1, 'cart', (string) Str::ulid(), ttlMinutes: 1);
    $order = app(ReserveStock::class)->handle($this->variant->ulid, 1, 'order', (string) Str::ulid(), ttlMinutes: 1);

    $this->travel(5)->minutes();
    app(ExpireReservations::class)->handle();

    expect(StockReservation::query()->whereKey($cart->id)->value('status'))->toBe('expired')
        ->and(StockReservation::query()->whereKey($order->id)->value('status'))->toBe('active');
});

it('announces low stock once when the threshold is crossed', function (): void {
    receive($this->variant, $this->main->ulid, 10);
    StockLevel::query()->where('variant_id', $this->variant->id)->update(['low_stock_threshold' => 3]);
    Event::fake([LowStockReached::class]);

    app(AdjustStock::class)->handle(new StockAdjustmentData($this->variant->ulid, $this->main->ulid, -6, 'sold offline'));
    app(AdjustStock::class)->handle(new StockAdjustmentData($this->variant->ulid, $this->main->ulid, -1, 'sold offline'));
    app(AdjustStock::class)->handle(new StockAdjustmentData($this->variant->ulid, $this->main->ulid, -1, 'sold offline'));

    Event::assertDispatchedTimes(LowStockReached::class, 1);
});

it('reconciles the balance against the ledger and reports drift', function (): void {
    receive($this->variant, $this->main->ulid, 4);

    expect(app(ReconcileInventory::class)->handle())->toBe(0);

    // Simulate a write that bypassed the Ledger, as the schema owner.
    DB::connection('pgsql_migrator')->table('stock_levels')->where('variant_id', $this->variant->id)->update(['on_hand' => 99]);

    expect(app(ReconcileInventory::class)->handle())->toBeGreaterThanOrEqual(1);

    DB::connection('pgsql_migrator')->table('stock_levels')->where('variant_id', $this->variant->id)->update(['on_hand' => 4]);
});

it('snapshots stock and valuation idempotently', function (): void {
    receive($this->variant, $this->main->ulid, 5, 300_00);
    $date = now()->addYears(5);

    app(SnapshotInventory::class)->handle($date);
    app(SnapshotInventory::class)->handle($date);

    $rows = DB::table('stock_snapshots_daily')->where('date', $date->toDateString())->where('variant_id', $this->variant->id)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->valuation_amount)->toBe(5 * 300_00);
});

it('never lets the application role rewrite a movement', function (): void {
    receive($this->variant, $this->main->ulid, 1);

    expect(fn () => InventoryMovement::query()->where('variant_id', $this->variant->id)->update(['quantity' => 100]))
        ->toThrow(Illuminate\Database\QueryException::class, 'permission denied');
});

afterEach(function (): void {
    expect(Product::query()->count())->toBeGreaterThan(0);
});
