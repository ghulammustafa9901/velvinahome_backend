<?php

declare(strict_types=1);

namespace Modules\Reviews\Providers;

use Modules\Support\Providers\ModuleServiceProvider;

final class ReviewsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Reviews';
    }
}
