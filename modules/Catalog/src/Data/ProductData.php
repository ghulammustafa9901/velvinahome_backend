<?php

declare(strict_types=1);

namespace Modules\Catalog\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A product with its option matrix and variants. A simple product has no options and exactly
 * one variant (spec §6.3: every sellable item is a variant).
 */
final class ProductData extends Data
{
    /**
     * @param  array<int, ProductOptionData>  $options
     * @param  array<int, VariantData>  $variants
     * @param  list<string>  $categoryUlids
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $specifications
     */
    public function __construct(
        #[Max(255)]
        public readonly string $title,
        #[DataCollectionOf(VariantData::class)]
        public readonly array $variants,
        #[DataCollectionOf(ProductOptionData::class)]
        public readonly array $options = [],
        #[Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/'), Max(200)]
        public readonly ?string $slug = null,
        public readonly ?string $description = null,
        #[Max(500)]
        public readonly ?string $shortDescription = null,
        public readonly ?string $brandUlid = null,
        public readonly array $categoryUlids = [],
        public readonly array $tags = [],
        public readonly array $specifications = [],
    ) {}
}
