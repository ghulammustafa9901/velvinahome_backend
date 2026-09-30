<?php

declare(strict_types=1);

namespace Modules\Identity\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
final class CustomerProfileData extends Data
{
    public function __construct(
        #[Max(100)]
        public readonly ?string $firstName = null,
        #[Max(100)]
        public readonly ?string $lastName = null,
        #[Regex('/^\+?[0-9 ()-]{7,20}$/')]
        public readonly ?string $phone = null,
        public readonly bool $acceptsMarketing = false,
    ) {}
}
