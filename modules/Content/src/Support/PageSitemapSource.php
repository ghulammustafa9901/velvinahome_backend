<?php

declare(strict_types=1);

namespace Modules\Content\Support;

use Modules\Content\Models\Page;
use Modules\Seo\Contracts\SitemapSource;

final class PageSitemapSource implements SitemapSource
{
    public function name(): string
    {
        return 'pages';
    }

    public function count(): int
    {
        return Page::query()->where('status', 'published')->count();
    }

    public function entries(int $offset, int $limit): array
    {
        $entries = [];

        foreach (Page::query()->where('status', 'published')->orderBy('id')->offset($offset)->limit($limit)->get() as $page) {
            $entries[] = ['path' => $page->path(), 'lastmod' => $page->updated_at->toIso8601ZuluString()];
        }

        return $entries;
    }
}
