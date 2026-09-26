<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Catalog\Data\ProductData;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Exceptions\CatalogRuleViolation;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductOption;
use Modules\Catalog\Models\ProductVariant;
use Modules\Catalog\Support\Slugs;
use Modules\Catalog\Support\VariantMatrix;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Support\Enums\Currency;
use Modules\Support\ValueObjects\Money;

/**
 * Creates a product as a draft, with its option matrix and variants, in one transaction.
 * Prices are in the store currency from tenant settings; the first variant is the default.
 */
final class CreateProduct implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantSettings $settings,
    ) {}

    public function handle(ProductData $data, ?Authorizable $actor = null): Product
    {
        $this->authorizePermission($actor, 'products.manage');

        $signatures = VariantMatrix::signatures($data->options, $data->variants);
        $currency = Currency::from($this->settings->string('store.currency', 'PKR'));

        return $this->db->connection()->transaction(function () use ($data, $signatures, $currency): Product {
            $product = Product::query()->create([
                'brand_id' => $data->brandUlid === null ? null
                    : (Brand::query()->where('ulid', $data->brandUlid)->value('id') ?? throw CatalogRuleViolation::unknown('brand', $data->brandUlid)),
                'title' => $data->title,
                'slug' => $data->slug ?? Slugs::unique(Product::query(), $data->title),
                'description' => $data->description,
                'short_description' => $data->shortDescription,
                'status' => ProductStatus::Draft,
                'tags' => array_values(array_unique(array_map('mb_strtolower', $data->tags))),
                'specifications' => $data->specifications,
            ]);

            $valueIds = [];

            foreach ($data->options as $position => $optionData) {
                $option = ProductOption::query()->create([
                    'product_id' => $product->id,
                    'name' => $optionData->name,
                    'position' => $position,
                ]);

                foreach (array_values(array_unique($optionData->values)) as $valuePosition => $value) {
                    $valueIds[$optionData->name][$value] = $option->values()->create(['value' => $value, 'position' => $valuePosition])->id;
                }
            }

            foreach ($data->variants as $index => $variantData) {
                $variant = new ProductVariant([
                    'product_id' => $product->id,
                    'sku' => $variantData->sku,
                    'barcode' => $variantData->barcode,
                    'title' => $variantData->optionValues === [] ? null : implode(' / ', $variantData->optionValues),
                    'option_signature' => $signatures[$index],
                    'weight_grams' => $variantData->weightGrams,
                    'allow_backorder' => $variantData->allowBackorder,
                    'is_default' => $index === 0,
                    'position' => $index,
                ]);
                $variant->price = Money::of($variantData->priceAmount, $currency);
                $variant->compare_at_price = $variantData->compareAtPriceAmount === null ? null : Money::of($variantData->compareAtPriceAmount, $currency);
                $variant->save();

                $variant->optionValues()->attach(array_map(
                    fn (string $name, string $value): int => $valueIds[$name][$value],
                    array_keys($variantData->optionValues),
                    array_values($variantData->optionValues),
                ));
            }

            $this->syncCategories($product, $data->categoryUlids);

            return $product;
        });
    }

    /**
     * @param  list<string>  $categoryUlids
     */
    private function syncCategories(Product $product, array $categoryUlids): void
    {
        if ($categoryUlids === []) {
            return;
        }

        $ids = Category::query()->whereIn('ulid', $categoryUlids)->pluck('id', 'ulid');

        foreach ($categoryUlids as $ulid) {
            if (! $ids->has($ulid)) {
                throw CatalogRuleViolation::unknown('category', $ulid);
            }
        }

        $sync = [];
        foreach (array_values($categoryUlids) as $position => $ulid) {
            $sync[$ids[$ulid]] = ['is_primary' => $position === 0];
        }

        $product->categories()->sync($sync);
    }
}
