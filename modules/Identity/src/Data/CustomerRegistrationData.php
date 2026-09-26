<?php

declare(strict_types=1);

namespace Modules\Identity\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
final class CustomerRegistrationData extends Data
{
    public function __construct(
        #[Email, Max(255)]
        public readonly string $email,
        public readonly string $password,
        #[Max(100)]
        public readonly ?string $firstName = null,
        #[Max(100)]
        public readonly ?string $lastName = null,
        #[Max(32)]
        public readonly ?string $phone = null,
        public readonly bool $acceptsMarketing = false,
    ) {}
}
