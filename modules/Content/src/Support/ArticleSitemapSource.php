<?php

declare(strict_types=1);

namespace Modules\Content\Support;

use Modules\Content\Models\Article;
use Modules\Platform\Contracts\FeatureFlags;
use Modules\Seo\Contracts\SitemapSource;

/**
 * Published blog posts, only while the brand has its blog switched on.
 */
final class ArticleSitemapSource implements SitemapSource
{
    public function __construct(private readonly FeatureFlags $features) {}

    public function name(): string
    {
        return 'articles';
    }

    public function count(): int
    {
        return $this->features->enabled('blog') ? Article::query()->where('status', 'published')->count() : 0;
    }

    public function entries(int $offset, int $limit): array
    {
        if (! $this->features->enabled('blog')) {
            return [];
        }

        $entries = [];
        foreach (Article::query()->where('status', 'published')->orderBy('id')->offset($offset)->limit($limit)->get() as $article) {
            $entries[] = ['path' => $article->path(), 'lastmod' => $article->updated_at->toIso8601ZuluString()];
        }

        return $entries;
    }
}
