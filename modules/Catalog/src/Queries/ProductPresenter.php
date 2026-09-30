<?php

declare(strict_types=1);

namespace Modules\Catalog\Queries;

use Illuminate\Support\Collection as BaseCollection;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductOption;
use Modules\Catalog\Models\ProductOptionValue;
use Modules\Catalog\Models\ProductVariant;
use Modules\Inventory\Contracts\StockReader;
use Modules\Seo\Contracts\Seo;
use Modules\Support\ValueObjects\Money;
use Modules\Support\ValueObjects\StringMap;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The storefront API shape of products (api-conventions §2): ULIDs only, money as
 * {amount, currency, formatted}, ISO-8601 UTC timestamps, absent optionals as null.
 * Price and stock come from PostgreSQL — never from the search index.
 */
final class ProductPresenter
{
    public function __construct(
        private readonly StockReader $stock,
        private readonly Seo $seo,
    ) {}

    /**
     * @param  iterable<Product>  $products
     * @return list<array{id: string, type: string, title: string, slug: string, short_description: string|null, brand: array{id: string, name: string, slug: string}|null, price: Money|null, compare_at_price: Money|null, price_range: array{min: Money, max: Money}|null, in_stock: bool, image: array{id: string, alt: string, url: string, width: int|null, height: int|null, sizes: array{thumb: array{url: string, width: int}|null, card: array{url: string, width: int}|null, large: array{url: string, width: int}|null}}|null, tags: list<string>, published_at: string|null, created_at: string}>
     *
     * @scramble-return list<array{id: string, type: string, title: string, slug: string, short_description: string|null, brand: array{id: string, name: string, slug: string}|null, price: \Modules\Support\ValueObjects\Money|null, compare_at_price: \Modules\Support\ValueObjects\Money|null, price_range: array{min: \Modules\Support\ValueObjects\Money, max: \Modules\Support\ValueObjects\Money}|null, in_stock: bool, image: array{id: string, alt: string, url: string, width: int|null, height: int|null, sizes: array{thumb: array{url: string, width: int}|null, card: array{url: string, width: int}|null, large: array{url: string, width: int}|null}}|null, tags: list<string>, published_at: string|null, created_at: string}>
     */
    public function summaries(iterable $products): array
    {
        $products = collect($products);
        $available = $this->stock->available($products->flatMap(fn (Product $p) => $p->variants->pluck('id'))->all());

        return array_values($products->map(fn (Product $product): array => $this->summary($product, $available))->all());
    }

    /**
     * @param  array<int, int>  $available
     * @return array{id: string, type: string, title: string, slug: string, short_description: string|null, brand: array{id: string, name: string, slug: string}|null, price: Money|null, compare_at_price: Money|null, price_range: array{min: Money, max: Money}|null, in_stock: bool, image: array{id: string, alt: string, url: string, width: int|null, height: int|null, sizes: array{thumb: array{url: string, width: int}|null, card: array{url: string, width: int}|null, large: array{url: string, width: int}|null}}|null, tags: list<string>, published_at: string|null, created_at: string}
     */
    public function summary(Product $product, array $available): array
    {
        /** @var BaseCollection<int, ProductVariant> $variants */
        $variants = $product->variants;
        $default = $variants->firstWhere('is_default', true) ?? $variants->first();
        $prices = $variants->map(fn (ProductVariant $variant): Money => $variant->price);

        return [
            'id' => $product->ulid,
            'type' => 'product',
            'title' => $product->title,
            'slug' => $product->slug,
            'short_description' => $product->short_description,
            'brand' => $product->brand === null ? null : ['id' => $product->brand->ulid, 'name' => $product->brand->name, 'slug' => $product->brand->slug],
            'price' => $default?->price,
            'compare_at_price' => $default?->compare_at_price,
            'price_range' => $prices->isEmpty() ? null : [
                'min' => Money::min(...$prices->all()),
                'max' => Money::max(...$prices->all()),
            ],
            'in_stock' => $variants->contains(fn (ProductVariant $variant): bool => $variant->allow_backorder || ($available[$variant->id] ?? 0) > 0),
            'image' => ($first = $product->getFirstMedia('images')) === null ? null : $this->image($first),
            'tags' => $product->tags,
            'published_at' => $product->published_at?->toIso8601ZuluString(),
            'created_at' => $product->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: string, type: string, title: string, slug: string, short_description: string|null, brand: array{id: string, name: string, slug: string}|null, price: Money|null, compare_at_price: Money|null, price_range: array{min: Money, max: Money}|null, in_stock: bool, image: array{id: string, alt: string, url: string, width: int|null, height: int|null, sizes: array{thumb: array{url: string, width: int}|null, card: array{url: string, width: int}|null, large: array{url: string, width: int}|null}}|null, tags: list<string>, published_at: string|null, created_at: string, description: string|null, images: list<array{id: string, alt: string, url: string, width: int|null, height: int|null, sizes: array{thumb: array{url: string, width: int}|null, card: array{url: string, width: int}|null, large: array{url: string, width: int}|null}}>, specifications: StringMap, options: list<array{name: string, values: list<string>}>, variants: list<array{id: string, sku: string, title: string|null, price: Money, compare_at_price: Money|null, options: StringMap, is_default: bool, in_stock: bool, low_stock: bool, weight_grams: int|null}>, categories: list<array{id: string, name: string, slug: string, is_primary: bool}>, seo: array{title: string, description: string|null, canonical_url: string|null, robots: string, og_image_url: string|null}}
     */
    public function detail(Product $product): array
    {
        $product->loadMissing(['variants.optionValues.option', 'options.values', 'brand', 'categories']);
        $available = $this->stock->available($product->variants->pluck('id')->all());
        $meta = $this->seo->meta('product', $product->ulid);

        return $this->summary($product, $available) + [
            'description' => $product->description,
            'images' => array_values($product->getMedia('images')->map(fn (Media $media): array => $this->image($media))->all()),
            'specifications' => StringMap::from($product->specifications),
            'options' => array_values($product->options->map(fn (ProductOption $option): array => [
                'name' => $option->name,
                'values' => array_values($option->values->map(fn (ProductOptionValue $value): string => $value->value)->all()),
            ])->all()),
            'variants' => array_values($product->variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->ulid,
                'sku' => $variant->sku,
                'title' => $variant->title,
                'price' => $variant->price,
                'compare_at_price' => $variant->compare_at_price,
                'options' => StringMap::from($variant->optionValues->mapWithKeys(
                    fn (ProductOptionValue $value): array => [$value->option->name => $value->value],
                )->all()),
                'is_default' => $variant->is_default,
                'in_stock' => $variant->allow_backorder || ($available[$variant->id] ?? 0) > 0,
                // Low stock is shown as a hint, never as an exact inventory count.
                'low_stock' => ! $variant->allow_backorder && ($available[$variant->id] ?? 0) > 0 && ($available[$variant->id] ?? 0) <= 3,
                'weight_grams' => $variant->weight_grams,
            ])->all()),
            'categories' => array_values($product->categories->map(fn (Category $category): array => [
                'id' => $category->ulid,
                'name' => $category->name,
                'slug' => $category->slug,
                'is_primary' => (bool) $category->getRelationValue('pivot')?->getAttribute('is_primary'),
            ])->all()),
            'seo' => [
                'title' => $meta->title ?? $product->title,
                'description' => $meta->description ?? $product->short_description,
                'canonical_url' => $meta->canonicalUrl,
                'robots' => $meta->robots ?? 'index,follow',
                'og_image_url' => $meta->ogImageUrl,
            ],
        ];
    }

    /**
     * Explicit dimensions and every generated size, so the storefront can render responsive,
     * layout-stable images (spec §7.7). A conversion not generated yet is null.
     *
     * @return array{id: string, alt: string, url: string, width: int|null, height: int|null, sizes: array{thumb: array{url: string, width: int}|null, card: array{url: string, width: int}|null, large: array{url: string, width: int}|null}}
     */
    private function image(Media $media): array
    {
        $size = fn (string $name): ?array => $media->hasGeneratedConversion($name) ? ['url' => $media->getUrl($name), 'width' => Product::IMAGE_CONVERSIONS[$name]] : null;
        $width = $media->getCustomProperty('width');
        $height = $media->getCustomProperty('height');

        return [
            'id' => $media->uuid,
            'alt' => (string) $media->getCustomProperty('alt', ''),
            'url' => $media->getUrl(),
            'width' => is_numeric($width) ? (int) $width : null,
            'height' => is_numeric($height) ? (int) $height : null,
            'sizes' => ['thumb' => $size('thumb'), 'card' => $size('card'), 'large' => $size('large')],
        ];
    }
}
