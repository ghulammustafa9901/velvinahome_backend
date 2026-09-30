<?php

declare(strict_types=1);

namespace Modules\Reviews\Providers;

use Modules\Integrations\Contracts\StorefrontCache;
use Modules\Reviews\Models\Review;
use Modules\Support\Providers\ModuleServiceProvider;

final class ReviewsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Reviews';
    }

    protected function bootModule(): void
    {
        $invalidate = fn (Review $review) => $this->app->make(StorefrontCache::class)->invalidate(['reviews:'.$review->product_ulid]);
        Review::saved($invalidate);
        Review::deleted($invalidate);
    }
}
