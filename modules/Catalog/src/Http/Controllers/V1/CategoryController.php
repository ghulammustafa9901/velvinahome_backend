<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Collection;
use Modules\Catalog\Queries\CatalogQuery;
use Modules\Catalog\Queries\ProductPresenter;

/**
 * Navigation: the visible category tree, one category with breadcrumbs, one collection.
 */
final class CategoryController
{
    public function tree(): JsonResponse
    {
        $byParent = [];

        foreach (Category::query()->where('is_visible', true)->orderBy('position')->orderBy('name')->get() as $category) {
            $byParent[$category->parent_id ?? 0][] = $category;
        }

        return response()->json(['data' => $this->branch($byParent, 0)])
            ->header('Cache-Control', 'public, max-age=0, s-maxage=300, stale-while-revalidate=600');
    }

    public function show(string $slug): JsonResponse
    {
        $category = Category::query()->where('slug', $slug)->where('is_visible', true)->firstOrFail();

        return response()->json(['data' => [
            'id' => $category->ulid,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'breadcrumbs' => $category->ancestors()->get()->map(fn (Category $ancestor): array => [
                'id' => $ancestor->ulid, 'name' => $ancestor->name, 'slug' => $ancestor->slug,
            ])->all(),
            'children' => $category->children()->where('is_visible', true)->get()->map(fn (Category $child): array => [
                'id' => $child->ulid, 'name' => $child->name, 'slug' => $child->slug,
            ])->all(),
        ]])->header('Cache-Control', 'public, max-age=0, s-maxage=300, stale-while-revalidate=600');
    }

    public function collection(string $slug, CatalogQuery $catalog, ProductPresenter $presenter): JsonResponse
    {
        $collection = Collection::query()->where('slug', $slug)->where('is_visible', true)->firstOrFail();

        $products = $catalog->live()
            ->join('collection_product', 'collection_product.product_id', '=', 'products.id')
            ->where('collection_product.collection_id', $collection->id)
            ->orderBy('collection_product.position')
            ->select('products.*')
            ->limit(96)
            ->get();

        return response()->json(['data' => [
            'id' => $collection->ulid,
            'title' => $collection->title,
            'slug' => $collection->slug,
            'description' => $collection->description,
            'products' => $presenter->summaries($products),
        ]])->header('Cache-Control', 'public, max-age=0, s-maxage=60, stale-while-revalidate=300');
    }

    /**
     * @param  array<int, list<Category>>  $byParent
     * @return list<array<string, mixed>>
     */
    private function branch(array $byParent, int $parentId): array
    {
        return array_map(fn (Category $category): array => [
            'id' => $category->ulid,
            'name' => $category->name,
            'slug' => $category->slug,
            'children' => $this->branch($byParent, $category->id),
        ], $byParent[$parentId] ?? []);
    }
}
