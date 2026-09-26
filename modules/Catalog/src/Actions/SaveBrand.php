<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Support\Slugs;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class SaveBrand implements Action
{
    use ChecksPermission;

    public function handle(?Brand $brand, string $name, ?string $description = null, bool $isVisible = true, ?Authorizable $actor = null): Brand
    {
        $this->authorizePermission($actor, 'products.manage');

        $brand ??= new Brand(['slug' => Slugs::unique(Brand::query(), $name)]);
        $brand->fill(['name' => $name, 'description' => $description, 'is_visible' => $isVisible])->save();

        return $brand;
    }
}
