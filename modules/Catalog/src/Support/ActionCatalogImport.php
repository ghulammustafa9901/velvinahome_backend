<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Actions\AttachProductImage;
use Modules\Catalog\Actions\ChangeProductStatus;
use Modules\Catalog\Actions\CreateProduct;
use Modules\Catalog\Actions\SaveBrand;
use Modules\Catalog\Actions\SaveCategory;
use Modules\Catalog\Actions\UpdateProductDetails;
use Modules\Catalog\Actions\UpdateVariant;
use Modules\Catalog\Contracts\CatalogImport;
use Modules\Catalog\Data\ProductData;
use Modules\Catalog\Data\ProductDetailsData;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

/**
 * Imports go through the catalog Actions (as the system, with no staff actor), so an import
 * can never create something the admin could not.
 */
final class ActionCatalogImport implements CatalogImport
{
    public function __construct(
        private readonly SaveCategory $saveCategory,
        private readonly SaveBrand $saveBrand,
        private readonly CreateProduct $createProduct,
        private readonly UpdateProductDetails $updateDetails,
        private readonly UpdateVariant $updateVariant,
        private readonly ChangeProductStatus $changeStatus,
        private readonly AttachProductImage $attachImage,
    ) {}

    public function saveCategory(?string $ulid, string $name, ?string $parentUlid, ?string $slug, ?string $description): string
    {
        $existing = $ulid === null ? null : Category::query()->where('ulid', $ulid)->first();

        return $this->saveCategory->handle($existing, $name, $parentUlid, $slug, $description)->ulid;
    }

    public function saveBrand(?string $ulid, string $name): string
    {
        $existing = $ulid === null ? null : Brand::query()->where('ulid', $ulid)->first();

        return $this->saveBrand->handle($existing, $name)->ulid;
    }

    public function saveProduct(?string $productUlid, ProductData $data, bool $active): array
    {
        $product = $productUlid === null ? null : Product::query()->where('ulid', $productUlid)->first();
        $skipped = [];

        if ($product === null) {
            // The source's slug when free; otherwise a numbered one (the old URL still redirects).
            $product = $this->createProduct->handle(new ProductData(
                title: $data->title,
                variants: $data->variants,
                options: $data->options,
                slug: $data->slug === null ? null : Slugs::unique(Product::query(), $data->slug),
                description: $data->description,
                shortDescription: $data->shortDescription,
                brandUlid: $data->brandUlid,
                categoryUlids: $data->categoryUlids,
                tags: $data->tags,
                specifications: $data->specifications,
            ));
        } else {
            $this->updateDetails->handle($product, new ProductDetailsData(
                title: $data->title,
                slug: $product->slug,
                description: $data->description,
                shortDescription: $data->shortDescription,
                brandUlid: $data->brandUlid,
                categoryUlids: $data->categoryUlids,
                tags: $data->tags,
                specifications: $data->specifications,
            ));

            $existing = ProductVariant::query()->where('product_id', $product->id)->get()->keyBy('sku');

            foreach ($data->variants as $variant) {
                $current = $existing->get($variant->sku);

                if (! $current instanceof ProductVariant) {
                    $skipped[] = $variant->sku;

                    continue;
                }

                $this->updateVariant->handle($current, $variant->priceAmount, $variant->compareAtPriceAmount, $variant->barcode ?? $current->barcode, $variant->weightGrams ?? $current->weight_grams, $variant->allowBackorder);
            }
        }

        $status = $active ? ProductStatus::Active : ProductStatus::Draft;
        if ($product->status !== $status) {
            $this->changeStatus->handle($product, $status);
        }

        return [
            'product' => $product->ulid,
            'variants' => ProductVariant::query()->where('product_id', $product->id)->pluck('ulid', 'sku')->all(),
            'skipped_skus' => $skipped,
        ];
    }

    public function hasImages(string $productUlid): bool
    {
        $product = Product::query()->where('ulid', $productUlid)->first();

        return $product !== null && $product->getMedia('images')->isNotEmpty();
    }

    public function attachImage(string $productUlid, string $path, ?string $alt): void
    {
        $this->attachImage->handle(Product::query()->where('ulid', $productUlid)->firstOrFail(), $path, $alt);
    }

    public function path(string $type, string $ulid): ?string
    {
        return match ($type) {
            'product' => ($slug = Product::query()->where('ulid', $ulid)->value('slug')) === null ? null : UpdateProductDetails::URL_PREFIX.$slug,
            'category' => ($slug = Category::query()->where('ulid', $ulid)->value('slug')) === null ? null : SaveCategory::URL_PREFIX.$slug,
            default => null,
        };
    }

    public function variantsBySku(array $skus): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($skus)), 1000) as $chunk) {
            $found += ProductVariant::query()->whereIn('sku', $chunk)->pluck('ulid', 'sku')->all();
        }

        return $found;
    }

    public function categoryBySlug(string $slug): ?string
    {
        return Category::query()->where('slug', mb_strtolower(trim($slug)))->value('ulid');
    }
}
