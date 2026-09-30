<?php

declare(strict_types=1);

namespace Modules\Seo\Contracts;

/**
 * Registration point for sitemap sources, called from other modules' service providers.
 */
interface SitemapSources
{
    /**
     * @param  class-string<SitemapSource>  $source
     */
    public function register(string $source): void;
}
