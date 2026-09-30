<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Contracts\CategoryDirectory;
use Modules\Catalog\Models\Category;

final class EloquentCategoryDirectory implements CategoryDirectory
{
    public function options(): array
    {
        $categories = Category::query()->orderBy('position')->orderBy('name')->get(['id', 'ulid', 'name', 'parent_id']);
        $byParent = $categories->groupBy(fn (Category $category): string => (string) $category->parent_id);
        $options = [];

        $walk = function (string $parent, string $prefix) use (&$walk, &$options, $byParent): void {
            foreach ($byParent->get($parent, collect()) as $category) {
                $label = $prefix === '' ? $category->name : $prefix.' › '.$category->name;
                $options[$category->ulid] = $label;
                $walk((string) $category->id, $label);
            }
        };

        $walk('', '');

        return $options;
    }
}
