<?php

declare(strict_types=1);

namespace Modules\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;

final class CompanyData extends Data
{
    public function __construct(
        #[Max(200)]
        public readonly string $legalName,
        #[Max(200)]
        public readonly string $displayName,
        #[Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/'), Max(60)]
        public readonly string $slug,
        #[Email]
        public readonly ?string $contactEmail = null,
        public readonly ?string $contactName = null,
        public readonly ?string $contactPhone = null,
        #[Size(2)]
        public readonly string $countryCode = 'PK',
        #[In(['prospect', 'active', 'suspended', 'ended'])]
        public readonly string $contractStatus = 'active',
    ) {}
}
