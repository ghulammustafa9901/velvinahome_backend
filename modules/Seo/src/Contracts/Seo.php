<?php

declare(strict_types=1);

namespace Modules\Seo\Contracts;

use Modules\Seo\Data\RedirectData;
use Modules\Seo\Data\SeoMetaData;

/**
 * What other modules may ask of the Seo module: record a moved URL, resolve an old one, and
 * read or write an entity's meta. Subjects are identified by type and public ULID only.
 */
interface Seo
{
    /**
     * Record that `$fromPath` now lives at `$toPath`. Existing redirects pointing at
     * `$fromPath` are re-pointed so there is never a chain, and a redirect away from
     * `$toPath` is removed so there is never a loop.
     */
    public function recordMove(string $fromPath, string $toPath, string $source = 'slug_change'): void;

    public function resolve(string $path): ?RedirectData;

    public function meta(string $subjectType, string $subjectUlid): SeoMetaData;

    public function saveMeta(string $subjectType, string $subjectUlid, SeoMetaData $meta): void;
}
