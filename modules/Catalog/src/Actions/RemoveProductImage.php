<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Catalog\Models\Product;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class RemoveProductImage implements Action
{
    use ChecksPermission;

    public function handle(Product $product, string $mediaUuid, ?Authorizable $actor = null): void
    {
        $this->authorizePermission($actor, 'products.manage');

        $product->getMedia('images')->firstWhere('uuid', $mediaUuid)?->delete();
    }
}
