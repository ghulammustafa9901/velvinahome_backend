<?php

declare(strict_types=1);

namespace Modules\Identity\Data;

use Spatie\LaravelData\Data;

final class OwnerAccountData extends Data
{
    public function __construct(
        public readonly string $ulid,
        public readonly string $email,
        public readonly bool $created,
        // Shown once on the go-live checklist, never stored or logged.
        public readonly ?string $temporaryPassword,
    ) {}
}
