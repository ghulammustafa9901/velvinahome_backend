<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Catalog\Filament\Resources\Products\Pages\CreateProductPage;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Filament\Resources\Stock\ListStockPage;
use Modules\Inventory\Models\StockLevel;
use Tests\Support\TestCatalog;
use Tests\Support\TestPlatform;

/*
| Plan 2.5: catalog and inventory screens in the tenant admin call the Actions, and follow
| permissions.
*/

beforeEach(function (): void {
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_B);
    $this->owner = TestPlatform::staff($this->brand, role: 'Owner');
    $this->admin = 'http://'.TestPlatform::adminHost(TestPlatform::BRAND_B);
});

function inAdmin(object $test, $staff): void
{
    tenancy()->initialize($test->brand);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $test->actingAs($staff, 'staff');
}

it('renders every catalog and inventory screen for an owner', function (string $path): void {
    $this->actingAs($this->owner, 'staff')->get($this->admin.$path)->assertOk();
})->with(['/products', '/products/create', '/categories', '/brands', '/stock', '/movements', '/locations']);

it('keeps an order handler out of catalog management', function (): void {
    $handler = TestPlatform::staff($this->brand, role: 'Order Handler');

    $this->actingAs($handler, 'staff')->get($this->admin.'/products/create')->assertForbidden();
    $this->actingAs($handler, 'staff')->get($this->admin.'/products')->assertOk();
});

it('creates a product with variants from the admin form, prices typed in rupees', function (): void {
    inAdmin($this, $this->owner);
    $sku = 'ADM-'.strtoupper(Str::random(6));

    Livewire::test(CreateProductPage::class)
        ->fillForm([
            'title' => 'Admin made '.$sku,
            'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
            'variants' => [
                ['sku' => $sku.'-S', 'price' => '2,499.50', 'option_values' => ['Size' => 'S']],
                ['sku' => $sku.'-M', 'price' => '2599', 'option_values' => ['Size' => 'M']],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('title', 'Admin made '.$sku)->firstOrFail();

    expect($product->variants()->pluck('price_amount', 'sku')->all())
        ->toEqual([$sku.'-S' => 249950, $sku.'-M' => 259900]);
});

it('receives stock through the workflow action', function (): void {
    inAdmin($this, $this->owner);
    $variant = TestCatalog::simpleProduct()->variants()->firstOrFail();

    Livewire::test(ListStockPage::class)
        ->callAction('receive', data: [
            'location' => TestCatalog::defaultLocation()->ulid,
            'lines' => [['variant' => $variant->ulid, 'quantity' => 7, 'unit_cost' => '1200']],
        ])
        ->assertHasNoActionErrors();

    expect(StockLevel::query()->where('variant_id', $variant->id)->value('on_hand'))->toBe(7)
        ->and($variant->fresh()?->cost?->amount)->toBe(120000);
});
