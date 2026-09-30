<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Contracts\ProductDirectory;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;

final class EloquentProductDirectory implements ProductDirectory
{
    public function isLive(string $productUlid): bool
    {
        return Product::query()
            ->where('ulid', $productUlid)
            ->where('status', ProductStatus::Active->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->exists();
    }

    public function titleOf(string $productUlid): ?string
    {
        $title = Product::query()->where('ulid', $productUlid)->value('title');

        return is_string($title) ? $title : null;
    }
}
