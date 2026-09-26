<?php

declare(strict_types=1);

namespace Modules\Identity\Data;

use Modules\Identity\Enums\StaffRole;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

final class StaffMemberData extends Data
{
    public function __construct(
        #[Max(120)]
        public readonly string $name,
        #[Email, Max(255)]
        public readonly string $email,
        public readonly StaffRole $role,
        #[In(['active', 'disabled'])]
        public readonly string $status = 'active',
    ) {}
}
