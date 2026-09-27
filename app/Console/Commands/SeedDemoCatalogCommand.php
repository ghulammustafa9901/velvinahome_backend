<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Catalog\Actions\ChangeProductStatus;
use Modules\Catalog\Actions\CreateProduct;
use Modules\Catalog\Actions\SaveBrand;
use Modules\Catalog\Actions\SaveCategory;
use Modules\Catalog\Actions\SaveSearchSynonym;
use Modules\Catalog\Data\ProductData;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Models\Location;
use Modules\Platform\Contracts\TenantDirectory;

/**
 * Local demo only: a small, believable catalog with stock in every demo brand, so the admin,
 * the storefront API and search have something to show. Composes several modules, which is
 * why it lives in the application layer rather than in any one module. Idempotent per brand.
 */
final class SeedDemoCatalogCommand extends Command
{
    protected $signature = 'platform:seed-demo-catalog';

    protected $description = 'Add a sample catalog with stock to every active demo brand (local only)';

    /**
     * @var list<array{title: string, category: string, price: int, compare: int|null, options: array<string, list<string>>, stock: int}>
     */
    private const PRODUCTS = [
        ['title' => 'Handwoven Cotton Cushion Cover', 'category' => 'Cushions', 'price' => 249900, 'compare' => 299900, 'options' => ['Color' => ['Beige', 'Rust', 'Olive']], 'stock' => 12],
        ['title' => 'Velvet Throw Pillow', 'category' => 'Cushions', 'price' => 189900, 'compare' => null, 'options' => [], 'stock' => 3],
        ['title' => 'Jute Table Runner', 'category' => 'Table Linen', 'price' => 149900, 'compare' => null, 'options' => ['Size' => ['150 cm', '200 cm']], 'stock' => 20],
        ['title' => 'Block Print Bedsheet Set', 'category' => 'Bedding', 'price' => 649900, 'compare' => 749900, 'options' => ['Size' => ['Single', 'Double', 'King']], 'stock' => 8],
        ['title' => 'Ceramic Serving Bowl', 'category' => 'Dining', 'price' => 99900, 'compare' => null, 'options' => [], 'stock' => 0],
    ];

    public function handle(TenantDirectory $tenants): int
    {
        if (app()->isProduction()) {
            $this->components->error('Demo data is never written in production.');

            return self::FAILURE;
        }

        foreach ($tenants->activeTenantIds() as $tenantId) {
            $count = $tenants->runIn($tenantId, fn (): int => $this->seedBrand());
            $this->components->twoColumnDetail($tenantId, "{$count} products added");
        }

        return self::SUCCESS;
    }

    private function seedBrand(): int
    {
        if (Product::query()->exists()) {
            return 0;
        }

        $home = app(SaveCategory::class)->handle(null, 'Home');
        $categories = [];
        foreach (['Cushions', 'Table Linen', 'Bedding', 'Dining'] as $name) {
            $categories[$name] = app(SaveCategory::class)->handle(null, $name, $home->ulid);
        }

        $brand = app(SaveBrand::class)->handle(null, 'House Label');
        $location = Location::query()->where('is_default', true)->firstOrFail();
        $prefix = strtoupper(substr((string) tenant()?->getAttribute('slug'), 0, 3));

        foreach (self::PRODUCTS as $index => $item) {
            $options = [];
            $variants = [];
            $combinations = [[]];

            foreach ($item['options'] as $name => $values) {
                $options[] = ['name' => $name, 'values' => $values];
                $combinations = array_merge(...array_map(
                    fn (array $combo): array => array_map(fn (string $value): array => $combo + [$name => $value], $values),
                    $combinations,
                ));
            }

            foreach ($combinations as $n => $combo) {
                $variants[] = [
                    'sku' => sprintf('%s-%03d-%02d', $prefix, $index + 1, $n + 1),
                    'priceAmount' => $item['price'],
                    'compareAtPriceAmount' => $item['compare'],
                    'optionValues' => $combo,
                ];
            }

            $product = app(CreateProduct::class)->handle(ProductData::from(array_filter([
                'title' => $item['title'],
                'shortDescription' => 'Made in Pakistan by small workshops. Demo product.',
                'brandUlid' => $brand->ulid,
                'categoryUlids' => [$categories[$item['category']]->ulid],
                'tags' => [strtolower($item['category'])],
                'options' => $options,
                'variants' => $variants,
            ], fn ($value): bool => $value !== [])));

            if ($item['stock'] > 0) {
                app(ReceiveStock::class)->handle(StockDocumentData::from([
                    'locationUlid' => $location->ulid,
                    'supplierReference' => 'DEMO-OPENING',
                    'lines' => $product->variants()->get()->map(fn ($variant): array => [
                        'variantUlid' => $variant->ulid,
                        'quantity' => $item['stock'],
                        'unitCostAmount' => intdiv($item['price'] * 45, 100),
                    ])->all(),
                ]));
            }

            app(ChangeProductStatus::class)->handle($product, ProductStatus::Active);
        }

        app(SaveSearchSynonym::class)->handle(null, ['takia', 'cushion', 'pillow']);
        app(SaveSearchSynonym::class)->handle(null, ['chadar', 'bedsheet']);

        return count(self::PRODUCTS);
    }
}
