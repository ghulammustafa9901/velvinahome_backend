<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Publishing is its own permission (`products.publish`): a content editor can prepare a
 * product without being able to put it on sale. Only active, published products are ever
 * indexed for search or served by the storefront API.
 */
final class ChangeProductStatus implements Action
{
    use ChecksPermission;

    public function handle(Product $product, ProductStatus $status, ?Authorizable $actor = null): Product
    {
        $this->authorizePermission($actor, $status === ProductStatus::Active ? 'products.publish' : 'products.manage');

        $product->forceFill([
            'status' => $status,
            'published_at' => $status === ProductStatus::Active ? ($product->published_at ?? now()) : $product->published_at,
        ])->save();

        return $product;
    }
}
