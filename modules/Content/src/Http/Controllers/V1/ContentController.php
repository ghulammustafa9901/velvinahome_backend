<?php

declare(strict_types=1);

namespace Modules\Content\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Modules\Content\Models\Menu;
use Modules\Content\Models\Page;

final class ContentController
{
    private const CACHE = 'public, max-age=0, s-maxage=300, stale-while-revalidate=600';

    public function page(string $slug): JsonResponse
    {
        $page = Page::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();

        return response()->json(['data' => $page->toApi()])->header('Cache-Control', self::CACHE);
    }

    public function menu(string $key): JsonResponse
    {
        $menu = Menu::query()->where('key', $key)->firstOrFail();

        return response()->json(['data' => $menu->toApi()])->header('Cache-Control', self::CACHE);
    }
}
