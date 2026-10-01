<?php

declare(strict_types=1);

namespace Modules\Content\Http\Controllers\V1;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Content\Models\Article;

/**
 * The blog (feature `blog`): published posts, newest first.
 */
final class ArticleController
{
    private const CACHE = 'public, max-age=0, s-maxage=300, stale-while-revalidate=600';

    #[QueryParameter('filter[tag]', description: 'Only posts with this tag.', type: 'string')]
    #[QueryParameter('per_page', description: '1–24, default 12.', type: 'integer')]
    #[QueryParameter('cursor', type: 'string')]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(24, max(1, (int) $request->integer('per_page', 12)));
        $tag = $request->input('filter.tag');

        $page = Article::query()->where('status', 'published')
            ->when(is_string($tag) && $tag !== '', fn ($query) => $query->whereJsonContains('tags', mb_strtolower((string) $tag)))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->cursorPaginate($perPage)->withQueryString();

        return response()->json([
            'data' => Article::listToApi($page->items()),
            'meta' => ['per_page' => $perPage, 'has_more' => $page->hasMorePages()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ])->header('Cache-Control', self::CACHE);
    }

    public function show(string $slug): JsonResponse
    {
        $article = Article::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();

        return response()->json(['data' => $article->toApi()])->header('Cache-Control', self::CACHE);
    }
}
