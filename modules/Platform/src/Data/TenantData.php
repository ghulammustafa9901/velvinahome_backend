<?php

declare(strict_types=1);

namespace Modules\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;

/**
 * Everything `tenant:create` needs to take a brand from nothing to a working store.
 */
final class TenantData extends Data
{
    private const HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    public function __construct(
        // The owning company, by slug.
        public readonly string $company,
        #[Max(120)]
        public readonly string $name,
        // Names the database and its roles, so it is short and fixed at provisioning.
        #[Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/'), Max(40)]
        public readonly string $slug,
        #[Regex(self::HOST_PATTERN)]
        public readonly string $storefrontHost,
        #[Regex(self::HOST_PATTERN)]
        public readonly string $adminHost,
        #[Regex(self::HOST_PATTERN)]
        public readonly string $apiHost,
        public readonly string $ownerName,
        #[Email]
        public readonly string $ownerEmail,
        public readonly ?string $plan = null,
        #[Size(3)]
        public readonly string $currency = 'PKR',
        public readonly string $timezone = 'Asia/Karachi',
        public readonly string $locale = 'en',
        public readonly string $themeId = 'default',
        // Link the owner to the company's multi-brand owner identity (spec §4.1a).
        public readonly bool $ownerIsCompanyOwner = true,
    ) {}
}
