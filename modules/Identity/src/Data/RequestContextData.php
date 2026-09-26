<?php

declare(strict_types=1);

namespace Modules\Identity\Data;

use Spatie\LaravelData\Data;

/**
 * The client facts an authentication Action records — never the Request itself, so Actions
 * stay callable from the API, the admin and the console alike.
 */
final class RequestContextData extends Data
{
    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $userAgent,
    ) {}
}
