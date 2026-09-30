<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use Modules\Catalog\Console\SearchReindexCommand;
use Modules\Catalog\Contracts\ProductDirectory;
use Modules\Catalog\Contracts\SearchIndex;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Catalog\Contracts\VariantLookup;
use Modules\Catalog\Listeners\RefreshSearchAfterStockChange;
use Modules\Catalog\Support\CategorySitemapSource;
use Modules\Catalog\Support\EloquentProductDirectory;
use Modules\Catalog\Support\EloquentVariantCatalog;
use Modules\Catalog\Support\EloquentVariantLookup;
use Modules\Catalog\Support\ProductSitemapSource;
use Modules\Catalog\Support\TypesenseSearchIndex;
use Modules\Inventory\Events\StockChanged;
use Modules\Seo\Contracts\SitemapSources;
use Modules\Support\Providers\ModuleServiceProvider;
use Typesense\Client;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Catalog';
    }

    protected function registerModule(): void
    {
        $this->app->bind(VariantCatalog::class, EloquentVariantCatalog::class);
        $this->app->bind(SearchIndex::class, TypesenseSearchIndex::class);
        $this->app->bind(VariantLookup::class, EloquentVariantLookup::class);
        $this->app->bind(ProductDirectory::class, EloquentProductDirectory::class);
        $this->app->singleton(Client::class, fn (): Client => new Client((array) config('scout.typesense.client-settings')));
    }

    protected function bootModule(): void
    {
        $sitemap = $this->app->make(SitemapSources::class);
        $sitemap->register(ProductSitemapSource::class);
        $sitemap->register(CategorySitemapSource::class);
    }

    protected function listeners(): array
    {
        return [
            StockChanged::class => [RefreshSearchAfterStockChange::class],
        ];
    }

    protected function consoleCommands(): array
    {
        return [SearchReindexCommand::class];
    }
}
