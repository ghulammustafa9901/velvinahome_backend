<?php

declare(strict_types=1);

namespace Modules\Content\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Modules\Content\Models\ContentEntry;
use Modules\Content\Models\ContentType;

/**
 * Published entries of a staff-defined content type (FAQs, testimonials, store locations…),
 * in the order staff set. Values hold only the type's declared fields.
 */
final class ContentEntryController
{
    private const CACHE = 'public, max-age=0, s-maxage=300, stale-while-revalidate=600';

    public const MAX_ENTRIES = 200;

    public function index(string $type): JsonResponse
    {
        $contentType = ContentType::query()->where('key', $type)->firstOrFail();

        $entries = ContentEntry::query()->where('content_type_id', $contentType->id)->where('status', 'published')
            ->orderBy('position')->orderBy('id')->limit(self::MAX_ENTRIES)->get();

        return response()->json([
            'data' => ContentEntry::listToApi($entries),
            'meta' => ['type' => ['key' => $contentType->key, 'name' => $contentType->name]],
        ])->header('Cache-Control', self::CACHE);
    }

    public function show(string $type, string $slug): JsonResponse
    {
        $contentType = ContentType::query()->where('key', $type)->firstOrFail();
        $entry = ContentEntry::query()->where('content_type_id', $contentType->id)->where('slug', $slug)->where('status', 'published')->firstOrFail();

        return response()->json(['data' => $entry->toApi()])->header('Cache-Control', self::CACHE);
    }
}
