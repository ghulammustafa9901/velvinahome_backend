<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Catalog\Data\ProductDetailsData;
use Modules\Catalog\Exceptions\CatalogRuleViolation;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Support\Slugs;
use Modules\Seo\Contracts\Seo;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Edits a product's descriptive fields. A slug change records a permanent redirect from the
 * old URL in the same transaction (spec §6.13), so no link or search ranking is lost.
 */
final class UpdateProductDetails implements Action
{
    use ChecksPermission;

    public const URL_PREFIX = '/products/';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Seo $seo,
    ) {}

    public function handle(Product $product, ProductDetailsData $data, ?Authorizable $actor = null): Product
    {
        $this->authorizePermission($actor, 'products.manage');

        return $this->db->connection()->transaction(function () use ($product, $data): Product {
            $oldSlug = $product->slug;
            $newSlug = $data->slug ?? $oldSlug;

            if ($newSlug !== $oldSlug) {
                $newSlug = Slugs::unique(Product::query(), $newSlug, $product->id);
            }

            $product->forceFill([
                'title' => $data->title,
                'slug' => $newSlug,
                'description' => $data->description,
                'short_description' => $data->shortDescription,
                'brand_id' => $data->brandUlid === null ? null
                    : (Brand::query()->where('ulid', $data->brandUlid)->value('id') ?? throw CatalogRuleViolation::unknown('brand', $data->brandUlid)),
                'tags' => array_values(array_unique(array_map('mb_strtolower', $data->tags))),
                'specifications' => $data->specifications,
            ])->save();

            if ($data->categoryUlids !== null) {
                $ids = Category::query()->whereIn('ulid', $data->categoryUlids)->pluck('id', 'ulid');
                $sync = [];

                foreach (array_values($data->categoryUlids) as $position => $ulid) {
                    $sync[$ids[$ulid] ?? throw CatalogRuleViolation::unknown('category', $ulid)] = ['is_primary' => $position === 0];
                }

                $product->categories()->sync($sync);
            }

            if ($newSlug !== $oldSlug) {
                $this->seo->recordMove(self::URL_PREFIX.$oldSlug, self::URL_PREFIX.$newSlug);
            }

            return $product;
        });
    }
}
