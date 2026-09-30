<?php

declare(strict_types=1);

namespace Modules\Content\Providers;

use Modules\Content\Models\Menu;
use Modules\Content\Models\Page;
use Modules\Content\Seeders\DefaultContentSeeder;
use Modules\Content\Support\PageSitemapSource;
use Modules\Integrations\Contracts\StorefrontCache;
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

        // Stale storefront pages are purged after commit (spec §6.13).
        $invalidate = fn (array $tags) => $this->app->make(StorefrontCache::class)->invalidate(array_values($tags));
        $page = fn (Page $page) => $invalidate(['pages', 'page:'.$page->slug]);
        Page::saved($page);
        Page::deleted($page);
        Menu::saved(fn () => $invalidate(['menus']));
        Menu::deleted(fn () => $invalidate(['menus']));
    }

    protected function tenantSeeders(): array
    {
        return [DefaultContentSeeder::class];
    }
}
