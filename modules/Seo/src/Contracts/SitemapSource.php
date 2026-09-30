<?php

declare(strict_types=1);

namespace Modules\Seo\Contracts;

/**
 * One kind of public URL for the sitemap (products, categories, pages…). Modules that own
 * public content register a source; Seo only pages through them.
 */
interface SitemapSource
{
    /**
     * Short, URL-safe name used in chunk names, e.g. "products".
     */
    public function name(): string;

    public function count(): int;

    /**
     * Storefront paths (not absolute URLs: the storefront knows its own origin), stable order.
     *
     * @return list<array{path: string, lastmod: string|null}>
     */
    public function entries(int $offset, int $limit): array;
}
