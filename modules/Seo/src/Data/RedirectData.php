<?php

declare(strict_types=1);

namespace Modules\Seo\Data;

use Spatie\LaravelData\Data;

final class RedirectData extends Data
{
    public function __construct(
        public readonly string $fromPath,
        public readonly string $toPath,
        public readonly int $statusCode,
    ) {}
}
