<?php

declare(strict_types=1);

namespace Modules\Inventory\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class LocationData extends Data
{
    /**
     * @param  array<string, mixed>|null  $address
     */
    public function __construct(
        #[Max(120)]
        public readonly string $name,
        #[Regex('/^[A-Za-z0-9_-]{1,32}$/')]
        public readonly string $code,
        #[In(['warehouse', 'shop', '3pl'])]
        public readonly string $type = 'warehouse',
        public readonly ?array $address = null,
        public readonly bool $isDefault = false,
        public readonly int $priority = 0,
    ) {}
}
