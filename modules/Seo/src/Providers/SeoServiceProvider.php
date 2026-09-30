<?php

declare(strict_types=1);

namespace Modules\Seo\Providers;

use Modules\Integrations\Contracts\StorefrontCache;
use Modules\Seo\Contracts\Seo;
use Modules\Seo\Contracts\SitemapSources;
use Modules\Seo\Models\Redirect;
use Modules\Seo\Support\DatabaseSeo;
use Modules\Seo\Support\SitemapRegistry;
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
        $this->app->singleton(SitemapRegistry::class);
        $this->app->alias(SitemapRegistry::class, SitemapSources::class);
    }

    protected function bootModule(): void
    {
        // Storefront 404s look redirects up (cached); a new or changed one must purge them.
        $invalidate = fn () => $this->app->make(StorefrontCache::class)->invalidate(['redirects']);
        Redirect::saved($invalidate);
        Redirect::deleted($invalidate);
    }
}
