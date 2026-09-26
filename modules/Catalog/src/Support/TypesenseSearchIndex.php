<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Contracts\SearchIndex;
use Modules\Catalog\Models\Product;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

final class TypesenseSearchIndex implements SearchIndex
{
    public function __construct(private readonly Client $typesense) {}

    public function ensureCollections(): void
    {
        $schema = (new Product)->typesenseCollectionSchema();

        try {
            $this->typesense->collections[$schema['name']]->retrieve();
        } catch (ObjectNotFound) {
            $this->typesense->collections->create($schema);
        }
    }

    public function reindex(): int
    {
        $this->dropCollections();
        $this->ensureCollections();

        $count = 0;

        Product::query()
            ->where('status', 'active')
            ->whereNotNull('published_at')
            ->with(['variants', 'brand', 'categories'])
            ->chunkById(200, function ($products) use (&$count): void {
                $searchable = $products->filter(fn (Product $product): bool => $product->shouldBeSearchable());
                (new Product)->queueMakeSearchable($searchable);
                $count += $searchable->count();
            });

        return $count;
    }

    public function dropCollections(): void
    {
        try {
            $this->typesense->collections[(new Product)->searchableAs()]->delete();
        } catch (ObjectNotFound) {
            // Already gone.
        }
    }
}
