<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;
use Modules\Settings\Contracts\OnboardingCheck;

final class CatalogLaunchCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Products on sale';
    }

    public function hint(): string
    {
        return 'Publish at least one product (Products → publish).';
    }

    public function passes(): bool
    {
        return Product::query()->where('status', ProductStatus::Active)->exists();
    }
}
