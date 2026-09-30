<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use DateTimeInterface;
use Modules\Catalog\Models\Category;
use Modules\Seo\Contracts\SitemapSource;

final class CategorySitemapSource implements SitemapSource
{
    public function name(): string
    {
        return 'categories';
    }

    public function count(): int
    {
        return Category::query()->where('is_visible', true)->count();
    }

    public function entries(int $offset, int $limit): array
    {
        $entries = [];

        foreach (Category::query()->where('is_visible', true)->orderBy('id')->offset($offset)->limit($limit)->get(['slug', 'updated_at']) as $category) {
            $updated = $category->getAttribute('updated_at');
            $entries[] = ['path' => '/categories/'.$category->slug, 'lastmod' => $updated instanceof DateTimeInterface ? $updated->format('Y-m-d\TH:i:s\Z') : null];
        }

        return $entries;
    }
}
