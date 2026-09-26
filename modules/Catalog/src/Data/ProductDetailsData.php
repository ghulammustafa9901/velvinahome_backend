<?php

declare(strict_types=1);

namespace Modules\Catalog\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class ProductDetailsData extends Data
{
    /**
     * @param  list<string>|null  $categoryUlids  null leaves categories unchanged
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $specifications
     */
    public function __construct(
        #[Max(255)]
        public readonly string $title,
        #[Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/'), Max(200)]
        public readonly ?string $slug = null,
        public readonly ?string $description = null,
        #[Max(500)]
        public readonly ?string $shortDescription = null,
        public readonly ?string $brandUlid = null,
        public readonly ?array $categoryUlids = null,
        public readonly array $tags = [],
        public readonly array $specifications = [],
    ) {}
}
