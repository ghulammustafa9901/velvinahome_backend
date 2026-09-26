<?php

declare(strict_types=1);

namespace Modules\Catalog\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

final class ProductOptionData extends Data
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        #[Max(60)]
        public readonly string $name,
        public readonly array $values,
    ) {}
}
