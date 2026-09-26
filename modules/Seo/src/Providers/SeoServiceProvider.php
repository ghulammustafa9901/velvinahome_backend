<?php

declare(strict_types=1);

namespace Modules\Seo\Providers;

use Modules\Seo\Contracts\Seo;
use Modules\Seo\Support\DatabaseSeo;
use Modules\Support\Providers\ModuleServiceProvider;

final class SeoServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Seo';
    }

    protected function registerModule(): void
    {
        $this->app->bind(Seo::class, DatabaseSeo::class);
    }
}
