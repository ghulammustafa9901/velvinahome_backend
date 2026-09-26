<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Unique, URL-safe slugs among live (non-deleted) rows of one table.
 */
final class Slugs
{
    /**
     * @param  Builder<covariant Model>  $query
     */
    public static function unique(Builder $query, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $base = mb_substr($base, 0, 180);
        $candidate = $base;
        $suffix = 2;

        while ((clone $query)->where('slug', $candidate)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
