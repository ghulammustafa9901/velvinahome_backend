<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Catalog\Models\Collection;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Support\Slugs;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * A manual collection and its ordered products. (Rule-based collections evaluate their
 * `rules` at query time and arrive with the promotions work.)
 */
final class SaveCollection implements Action
{
    use ChecksPermission;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  list<string>  $productUlids  in display order
     */
    public function handle(?Collection $collection, string $title, array $productUlids, ?string $description = null, bool $isVisible = true, ?Authorizable $actor = null): Collection
    {
        $this->authorizePermission($actor, 'products.manage');

        return $this->db->connection()->transaction(function () use ($collection, $title, $productUlids, $description, $isVisible): Collection {
            $collection ??= new Collection(['slug' => Slugs::unique(Collection::query(), $title)]);
            $collection->fill(['title' => $title, 'description' => $description, 'is_visible' => $isVisible, 'is_dynamic' => false])->save();

            $ids = Product::query()->whereIn('ulid', $productUlids)->pluck('id', 'ulid');
            $sync = [];

            foreach (array_values($productUlids) as $position => $ulid) {
                if ($ids->has($ulid)) {
                    $sync[$ids[$ulid]] = ['position' => $position];
                }
            }

            $collection->products()->sync($sync);

            return $collection;
        });
    }
}
