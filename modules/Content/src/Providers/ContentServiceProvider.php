<?php

declare(strict_types=1);

namespace Modules\Content\Providers;

use Modules\Content\Models\Article;
use Modules\Content\Models\ContentEntry;
use Modules\Content\Models\Menu;
use Modules\Content\Models\Page;
use Modules\Content\Seeders\DefaultContentSeeder;
use Modules\Content\Support\ArticleSitemapSource;
use Modules\Content\Support\HomePageCheck;
use Modules\Content\Support\PageSitemapSource;
use Modules\Integrations\Contracts\StorefrontCache;
use Modules\Seo\Contracts\SitemapSources;
use Modules\Settings\Contracts\OnboardingChecks;
use Modules\Support\Providers\ModuleServiceProvider;

final class ContentServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Content';
    }

    protected function bootModule(): void
    {
        $this->app->make(OnboardingChecks::class)->register(HomePageCheck::class);
        $this->app->make(SitemapSources::class)->register(PageSitemapSource::class);
        $this->app->make(SitemapSources::class)->register(ArticleSitemapSource::class);

        // Stale storefront pages are purged after commit (spec §6.13).
        $invalidate = fn (array $tags) => $this->app->make(StorefrontCache::class)->invalidate(array_values($tags));
        $page = fn (Page $page) => $invalidate(['pages', 'page:'.$page->slug]);
        Page::saved($page);
        Page::deleted($page);
        Menu::saved(fn () => $invalidate(['menus']));
        Menu::deleted(fn () => $invalidate(['menus']));
        $article = fn (Article $article) => $invalidate(['articles', 'article:'.$article->slug, ...($article->getOriginal('slug') !== null && $article->getOriginal('slug') !== $article->slug ? ['article:'.$article->getOriginal('slug')] : [])]);
        Article::saved($article);
        Article::deleted($article);
        $entry = fn (ContentEntry $entry) => $invalidate(['content:'.$entry->type->key]);
        ContentEntry::saved($entry);
        ContentEntry::deleted($entry);
    }

    protected function tenantSeeders(): array
    {
        return [DefaultContentSeeder::class];
    }
}
