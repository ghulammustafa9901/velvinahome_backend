<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Customers\Models\CustomerAddress;
use Modules\Identity\Models\Customer;
use Modules\Imports\Actions\StartWooCommerceImport;
use Modules\Imports\Models\ImportedOrder;
use Modules\Imports\Models\ImportRun;
use Modules\Inventory\Contracts\StockReader;
use Modules\Seo\Contracts\Seo;
use Tests\Support\TestStore;

/*
| Phase 6 exit criterion: a WooCommerce store can be imported with redirects. The store is
| faked at the HTTP layer with the shapes the WooCommerce REST API v3 returns.
*/

beforeEach(function (): void {
    $this->owner = TestStore::staff(role: 'Owner');
    // A fresh store per test: the test store is shared across tests and runs.
    $this->id = Str::lower(Str::random(8));
    $this->store = "http://shop-{$this->id}.example.test";
    $this->sku = strtoupper($this->id);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeStore(object $test, array $overrides = []): void
{
    $sku = $test->sku;
    $api = $test->store.'/wp-json/wc/v3/';
    $canvas = imagecreatetruecolor(20, 20);
    ob_start();
    imagepng($canvas);
    $image = (string) ob_get_clean();

    $products = $overrides['products'] ?? [
        [
            'id' => 101, 'name' => 'Linen Sheet &amp; Pillow Set', 'slug' => "linen-sheet-{$test->id}", 'type' => 'simple', 'status' => 'publish',
            'permalink' => "{$test->store}/product/linen-sheet-{$test->id}/",
            'description' => '<p>Soft <strong>linen</strong>.</p><p>Made in Lahore.</p>', 'short_description' => '<p>Soft linen.</p>',
            'sku' => "{$sku}-SHEET", 'regular_price' => '1499.50', 'sale_price' => '1299.50', 'price' => '1299.50',
            'manage_stock' => true, 'stock_quantity' => 7, 'backorders' => 'no', 'weight' => '0.85',
            'categories' => [['id' => 11]], 'tags' => [['name' => 'Linen']],
            'images' => [['src' => "{$test->store}/wp-content/uploads/sheet.jpg", 'alt' => 'Sheet']],
            'attributes' => [],
        ],
        [
            'id' => 102, 'name' => 'Cotton Kurta', 'slug' => "cotton-kurta-{$test->id}", 'type' => 'variable', 'status' => 'draft',
            'permalink' => "{$test->store}/product/cotton-kurta-{$test->id}/",
            'description' => '', 'short_description' => '', 'sku' => '', 'regular_price' => '', 'sale_price' => '', 'price' => '2500',
            'categories' => [['id' => 10]], 'tags' => [], 'images' => [],
            'attributes' => [['name' => 'Size', 'variation' => true, 'options' => ['S', 'M']], ['name' => 'Fabric', 'variation' => false, 'options' => ['Cotton']]],
        ],
        ['id' => 103, 'name' => 'Gift Bundle', 'slug' => 'gift-bundle', 'type' => 'grouped', 'status' => 'publish'],
    ];

    Http::fake(array_merge([
        $api.'data/currencies/current' => Http::response(['code' => $overrides['currency'] ?? 'PKR', 'name' => 'Pakistani rupee']),
        $api.'products/categories*' => Http::response([
            ['id' => 11, 'name' => 'Bed Linen', 'slug' => "bed-linen-{$test->id}", 'parent' => 10, 'description' => ''],
            ['id' => 10, 'name' => 'Home', 'slug' => "home-{$test->id}", 'parent' => 0, 'description' => '<p>All home</p>'],
        ], 200, ['X-WP-TotalPages' => '1']),
        $api.'products/102/variations*' => Http::response([
            ['id' => 201, 'sku' => "{$sku}-KURTA-S", 'regular_price' => '2500', 'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 3, 'attributes' => [['name' => 'Size', 'option' => 'S']]],
            ['id' => 202, 'sku' => "{$sku}-KURTA-M", 'regular_price' => '2600', 'sale_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'attributes' => [['name' => 'Size', 'option' => 'M']]],
            ['id' => 203, 'sku' => "{$sku}-KURTA-ANY", 'regular_price' => '2600', 'sale_price' => '', 'attributes' => []],
        ], 200, ['X-WP-TotalPages' => '1']),
        $api.'products*' => Http::response($products, 200, ['X-WP-TotalPages' => '1']),
        $api.'customers*' => Http::response([
            ['id' => 5, 'email' => "ayesha-{$test->id}@example.test", 'first_name' => 'Ayesha', 'last_name' => 'Khan',
                'billing' => ['first_name' => 'Ayesha', 'last_name' => 'Khan', 'phone' => '03001234567', 'address_1' => 'House 1', 'city' => 'Lahore', 'state' => 'Punjab', 'postcode' => '54000', 'country' => 'PK'],
                'shipping' => ['first_name' => '', 'address_1' => '', 'city' => '']],
        ], 200, ['X-WP-TotalPages' => '1']),
        $api.'orders*' => Http::response([
            ['id' => 900, 'number' => '900', 'status' => 'completed', 'currency' => 'PKR', 'customer_id' => 5,
                'date_created_gmt' => '2025-01-15T10:00:00', 'date_completed_gmt' => '2025-01-18T12:00:00',
                'discount_total' => '100.00', 'shipping_total' => '250.00', 'total_tax' => '0.00', 'total' => '2749.50',
                'payment_method_title' => 'Cash on delivery',
                'billing' => ['first_name' => 'Ayesha', 'last_name' => 'Khan', 'email' => "ayesha-{$test->id}@example.test", 'phone' => '03001234567'],
                'shipping' => ['first_name' => 'Ayesha', 'last_name' => 'Khan', 'address_1' => 'House 1', 'city' => 'Lahore', 'country' => 'PK'],
                'line_items' => [['name' => 'Linen Sheet', 'sku' => "{$sku}-SHEET", 'quantity' => 2, 'subtotal' => '2599.00', 'total' => '2499.50']]],
        ], 200, ['X-WP-TotalPages' => '1']),
        $test->store.'/wp-content/*' => Http::response((string) $image, 200, ['Content-Type' => 'image/png']),
    ], $overrides['extra'] ?? []));
}

function startImport(object $test, bool $dryRun, array $entities = ['categories', 'products', 'customers', 'orders']): ImportRun
{
    $run = app(StartWooCommerceImport::class)->handle($test->store, 'ck_'.str_repeat('a', 40), 'cs_'.str_repeat('b', 40), $dryRun, $entities, $test->owner);

    return $run->refresh();
}

it('checks a store in a dry run and writes nothing', function (): void {
    fakeStore($this);
    $run = startImport($this, dryRun: true);

    expect($run->status)->toBe('completed')
        ->and($run->counts['categories'])->toBe(['would_create' => 2])
        ->and($run->counts['products'])->toBe(['skipped' => 1, 'would_create' => 2])
        ->and($run->counts['customers'])->toBe(['would_create' => 1])
        ->and($run->counts['orders'])->toBe(['would_create' => 1])
        ->and(ProductVariant::query()->where('sku', "{$this->sku}-SHEET")->exists())->toBeFalse()
        ->and(Category::query()->where('slug', "home-{$this->id}")->exists())->toBeFalse()
        ->and(app(Seo::class)->resolve("/product/linen-sheet-{$this->id}/"))->toBeNull()
        ->and($run->getRawOriginal('credentials'))->toBeNull();
});

it('imports categories, products, stock, images, customers, order history and redirects', function (): void {
    fakeStore($this);
    $run = startImport($this, dryRun: false);

    expect($run->status)->toBe('completed')
        ->and($run->error)->toBeNull()
        ->and($run->getRawOriginal('credentials'))->toBeNull();

    // Categories keep their tree.
    $home = Category::query()->where('slug', "home-{$this->id}")->firstOrFail();
    $bed = Category::query()->where('slug', "bed-linen-{$this->id}")->firstOrFail();
    expect($bed->parent_id)->toBe($home->id)->and($home->description)->toBe('All home');

    // Simple product: sale price with the regular one as compare-at, text description, stock, image.
    $sheet = ProductVariant::query()->where('sku', "{$this->sku}-SHEET")->firstOrFail();
    $product = Product::query()->findOrFail($sheet->product_id);

    expect($product->title)->toBe('Linen Sheet & Pillow Set')
        ->and($product->status->value)->toBe('active')
        ->and($product->description)->toBe("Soft linen.\nMade in Lahore.")
        ->and($sheet->price->amount)->toBe(129_950)
        ->and($sheet->compare_at_price?->amount)->toBe(149_950)
        ->and($sheet->weight_grams)->toBe(850)
        ->and(app(StockReader::class)->available([$sheet->id])[$sheet->id])->toBe(7)
        ->and($product->getMedia('images'))->toHaveCount(1);

    // Variable product: one option, two variants; the "any size" variation is reported, not guessed.
    $kurtaS = ProductVariant::query()->where('sku', "{$this->sku}-KURTA-S")->firstOrFail();
    expect(ProductVariant::query()->where('product_id', $kurtaS->product_id)->count())->toBe(2)
        ->and(Product::query()->findOrFail($kurtaS->product_id)->status->value)->toBe('draft')
        ->and(app(StockReader::class)->available([$kurtaS->id])[$kurtaS->id])->toBe(3)
        ->and(collect($run->issues)->pluck('message')->implode(' '))->toContain('"any" values are not supported')
        ->and(collect($run->issues)->pluck('message')->implode(' '))->toContain('grouped products are not supported');

    // Old URLs answer with a permanent redirect to the new ones.
    expect(app(Seo::class)->resolve("/product/linen-sheet-{$this->id}/")?->toPath)->toBe('/products/'.$product->slug)
        ->and(app(Seo::class)->resolve("/product-category/home-{$this->id}/bed-linen-{$this->id}/")?->toPath)->toBe('/categories/'.$bed->slug)
        ->and($run->counts['redirects']['recorded'])->toBe(4);

    // Customers arrive without a password (they claim the account) and never opted in to marketing.
    $customer = Customer::query()->where('email', "ayesha-{$this->id}@example.test")->firstOrFail();
    expect($customer->password)->toBeNull()
        ->and($customer->accepts_marketing)->toBeFalse()
        ->and(CustomerAddress::query()->where('customer_id', $customer->id)->count())->toBe(1);

    // Order history, read-only, linked to the customer.
    $order = ImportedOrder::query()->where('external_id', '900')->where('source', 'woocommerce:shop-'.$this->id.'.example.test')->firstOrFail();
    expect($order->total->amount)->toBe(274_950)
        ->and($order->subtotal->amount)->toBe(259_900)
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->lines[0]['quantity'])->toBe(2)
        ->and($order->placed_at->toIso8601String())->toBe('2025-01-15T10:00:00+00:00');
});

it('updates instead of duplicating when run again', function (): void {
    fakeStore($this);
    startImport($this, dryRun: false);

    fakeStore($this, ['extra' => []]);
    $again = startImport($this, dryRun: false);

    expect($again->status)->toBe('completed')
        ->and($again->counts['products'])->toBe(['skipped' => 1, 'updated' => 2])
        ->and($again->counts['customers'])->toBe(['updated' => 1])
        ->and($again->counts['orders'])->toBe(['updated' => 1])
        ->and(ProductVariant::query()->where('sku', "{$this->sku}-SHEET")->count())->toBe(1)
        ->and(Product::query()->findOrFail(ProductVariant::query()->where('sku', "{$this->sku}-SHEET")->value('product_id'))->getMedia('images'))->toHaveCount(1)
        ->and(CustomerAddress::query()->where('customer_id', Customer::query()->where('email', "ayesha-{$this->id}@example.test")->value('id'))->count())->toBe(1);
});

it('refuses a store selling in another currency', function (): void {
    fakeStore($this, ['currency' => 'USD']);
    $run = startImport($this, dryRun: false);

    expect($run->status)->toBe('failed')
        ->and($run->error)->toContain('USD')
        ->and(ProductVariant::query()->where('sku', "{$this->sku}-SHEET")->exists())->toBeFalse();
});

it('only reaches public https stores, with well-formed keys, one run at a time', function (): void {
    config(['store.imports.allow_private_hosts' => false]);
    $start = fn (string $url, string $key = 'ck_aaaaaaaaaaaaaaaaaaaaaaaa') => app(StartWooCommerceImport::class)->handle($url, $key, 'cs_bbbbbbbbbbbbbbbbbbbbbbbb', true, ['products'], $this->owner);

    expect(fn () => $start('http://93.184.215.14'))->toThrow(ValidationException::class, 'https')
        ->and(fn () => $start('https://127.0.0.1'))->toThrow(ValidationException::class, 'not reachable')
        ->and(fn () => $start('https://10.0.0.5'))->toThrow(ValidationException::class, 'not reachable')
        ->and(fn () => $start('https://user:pass@93.184.215.14'))->toThrow(ValidationException::class)
        ->and(fn () => $start('https://93.184.215.14', 'not-a-key'))->toThrow(ValidationException::class, 'ck_');

    config(['store.imports.allow_private_hosts' => true]);
    ImportRun::query()->create(['source' => 'woocommerce', 'status' => 'running', 'dry_run' => true, 'options' => [], 'counts' => [], 'issues' => []]);

    try {
        expect(fn () => startImport($this, dryRun: true))->toThrow(ValidationException::class, 'still running');
    } finally {
        ImportRun::query()->where('status', 'running')->update(['status' => 'failed']);
    }
});

it('lets only staff who manage settings start a WooCommerce import', function (): void {
    fakeStore($this);
    $manager = TestStore::staff(role: 'Manager');

    expect(fn () => app(StartWooCommerceImport::class)->handle($this->store, 'ck_'.str_repeat('a', 40), 'cs_'.str_repeat('b', 40), true, ['products'], $manager))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});
