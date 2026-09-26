<?php

declare(strict_types=1);

namespace Modules\Seo\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Seo\Contracts\Seo;
use Modules\Seo\Data\RedirectData;
use Modules\Seo\Data\SeoMetaData;
use Modules\Seo\Models\Redirect;
use Modules\Seo\Models\SeoMeta;

final class DatabaseSeo implements Seo
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function recordMove(string $fromPath, string $toPath, string $source = 'slug_change'): void
    {
        $from = self::normalise($fromPath);
        $to = self::normalise($toPath);

        if ($from === $to) {
            return;
        }

        $this->db->connection()->transaction(function () use ($from, $to, $source): void {
            // The new URL is live again: any redirect away from it would now be a loop.
            Redirect::query()->where('from_path', $to)->delete();

            // Collapse chains: everything that pointed at the old URL now points at the new one.
            Redirect::query()->where('to_path', $from)->update(['to_path' => $to, 'updated_at' => now()]);

            Redirect::query()->updateOrCreate(
                ['from_path' => $from],
                ['to_path' => $to, 'status_code' => 301, 'source' => $source],
            );
        });
    }

    public function resolve(string $path): ?RedirectData
    {
        $redirect = Redirect::query()->where('from_path', self::normalise($path))->first();

        if ($redirect === null) {
            return null;
        }

        Redirect::query()->whereKey($redirect->id)->update([
            'hits' => $this->db->raw('hits + 1'),
            'last_hit_at' => now(),
        ]);

        return new RedirectData($redirect->from_path, $redirect->to_path, $redirect->status_code);
    }

    public function meta(string $subjectType, string $subjectUlid): SeoMetaData
    {
        $meta = SeoMeta::query()->where('subject_type', $subjectType)->where('subject_ulid', $subjectUlid)->first();

        return new SeoMetaData(
            title: $meta?->title,
            description: $meta?->description,
            canonicalUrl: $meta?->canonical_url,
            robots: $meta?->robots,
            ogImageUrl: $meta?->og_image_url,
        );
    }

    public function saveMeta(string $subjectType, string $subjectUlid, SeoMetaData $meta): void
    {
        SeoMeta::query()->updateOrCreate(
            ['subject_type' => $subjectType, 'subject_ulid' => $subjectUlid],
            [
                'title' => $meta->title,
                'description' => $meta->description,
                'canonical_url' => $meta->canonicalUrl,
                'robots' => $meta->robots,
                'og_image_url' => $meta->ogImageUrl,
            ],
        );
    }

    /**
     * One canonical form per path: leading slash, no trailing slash, lower-case, no query.
     */
    public static function normalise(string $path): string
    {
        $path = strtolower(trim((string) parse_url($path, PHP_URL_PATH)));

        return '/'.trim($path, '/');
    }
}
