<?php

declare(strict_types=1);

namespace Modules\Seo\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Data;

final class SeoMetaData extends Data
{
    public function __construct(
        #[Max(255)]
        public readonly ?string $title = null,
        #[Max(500)]
        public readonly ?string $description = null,
        #[Url]
        public readonly ?string $canonicalUrl = null,
        #[In(['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'])]
        public readonly ?string $robots = null,
        #[Url]
        public readonly ?string $ogImageUrl = null,
    ) {}
}
