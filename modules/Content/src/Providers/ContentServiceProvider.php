<?php

declare(strict_types=1);

namespace Modules\Content\Providers;

use Modules\Content\Seeders\DefaultContentSeeder;
use Modules\Content\Support\PageSitemapSource;
use Modules\Seo\Contracts\SitemapSources;
use Modules\Support\Providers\ModuleServiceProvider;

final class ContentServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Content';
    }

    protected function bootModule(): void
    {
        $this->app->make(SitemapSources::class)->register(PageSitemapSource::class);
    }

    protected function tenantSeeders(): array
    {
        return [DefaultContentSeeder::class];
    }
}
