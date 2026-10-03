<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Searchable;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\ProductVariant;
use Modules\Inventory\Contracts\StockReader;

/**
 * Search behaviour of Product (ADR-0005).
 *
 * - One collection, `{SCOUT_PREFIX}products`; the prefix keeps two installations that share a
 *   Typesense server apart.
 * - The document id is the product ULID — internal ids never leave the database.
 * - Only active, published products are indexed; anything else is removed from the index.
 * - Typo tolerance on title, brand, categories and tags; none on SKUs.
 */
trait ProductSearch
{
    use Searchable;

    public function searchableAs(): string
    {
        return config('scout.prefix').'products';
    }

    public function getScoutKey(): mixed
    {
        return $this->ulid;
    }

    public function getScoutKeyName(): string
    {
        return 'ulid';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished() && ! $this->trashed();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(['variants', 'brand', 'categories']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['variants', 'brand', 'categories']);

        /** @var \Illuminate\Support\Collection<int, ProductVariant> $variants */
        $variants = $this->variants;
        $prices = $variants->map(fn (ProductVariant $variant): int => $variant->price->amount);
        $available = app(StockReader::class)->available($variants->pluck('id')->all());
        $inStock = $variants->contains(fn (ProductVariant $variant): bool => $variant->allow_backorder || ($available[$variant->id] ?? 0) > 0);

        $categoryIds = $this->categories->pluck('id')->all();
        $path = $categoryIds === [] ? collect() : Category::query()
            ->join('category_closure', 'category_closure.ancestor_id', '=', 'categories.id')
            ->whereIn('category_closure.descendant_id', $categoryIds)
            ->distinct()
            ->get(['categories.ulid', 'categories.name']);

        return [
            'id' => $this->ulid,
            'title' => $this->title,
            'slug' => $this->slug,
            'skus' => $variants->pluck('sku')->values()->all(),
            'brand' => $this->brand->name ?? '',
            'category_path' => $path->pluck('name')->values()->all(),
            'category_ids' => $path->pluck('ulid')->values()->all(),
            'tags' => array_values($this->tags ?? []),
            'price_min' => (int) ($prices->min() ?? 0),
            'price_max' => (int) ($prices->max() ?? 0),
            'in_stock' => $inStock,
            'sales_rank' => 0,
            'created_at' => $this->created_at->getTimestamp(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'slug', 'type' => 'string', 'index' => false, 'optional' => true],
                ['name' => 'skus', 'type' => 'string[]'],
                ['name' => 'brand', 'type' => 'string', 'facet' => true],
                ['name' => 'category_path', 'type' => 'string[]', 'facet' => true],
                ['name' => 'category_ids', 'type' => 'string[]', 'facet' => true],
                ['name' => 'tags', 'type' => 'string[]', 'facet' => true],
                ['name' => 'price_min', 'type' => 'int64', 'facet' => true],
                ['name' => 'price_max', 'type' => 'int64'],
                ['name' => 'in_stock', 'type' => 'bool', 'facet' => true],
                ['name' => 'sales_rank', 'type' => 'int32'],
                ['name' => 'created_at', 'type' => 'int64'],
            ],
            // No custom token separators: a SKU such as "VH-CC-001" must stay one token, or
            // a partial SKU would match every product sharing its prefix.
            'default_sorting_field' => 'created_at',
            // Synonyms (Roman Urdu and local spellings), managed in the admin.
            'synonym_sets' => [TypesenseSynonyms::setName()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function typesenseSearchParameters(): array
    {
        return [
            'query_by' => 'title,brand,category_path,tags,skus',
            // Up to two typos on words, none on SKUs: a mistyped SKU must never return a
            // different product.
            'num_typos' => '2,1,1,1,0',
            'prefix' => 'true,true,true,true,false',
            'query_by_weights' => '4,2,2,1,4',
        ];
    }
}
