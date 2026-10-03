<?php

declare(strict_types=1);

namespace Modules\Settings\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Timezone;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Data;

/**
 * Everything on the admin's Store settings page except the logo (SaveStoreLogo) and the
 * currency, which is fixed at installation because prices and orders carry it.
 */
final class StoreSettingsData extends Data
{
    /**
     * @param  array<string, string>|null  $themeTokens  design-token overrides, e.g. color-accent => #8a5a44
     * @param  array<string, bool>|null  $features
     */
    public function __construct(
        #[Max(120)]
        public readonly string $name,
        #[Url(['http', 'https']), Max(255)]
        public readonly string $url,
        #[Timezone]
        public readonly string $timezone,
        #[Regex('/^[a-z]{2}(-[A-Z]{2})?$/')]
        public readonly string $locale,
        #[Regex('/^[A-Z]{2}$/')]
        public readonly string $country,
        #[Email, Max(255)]
        public readonly ?string $contactEmail,
        #[Max(40)]
        public readonly ?string $contactPhone,
        #[Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/'), Max(60)]
        public readonly string $themeId,
        #[In(['inclusive', 'exclusive'])]
        public readonly string $taxMode,
        public readonly bool $allowIndexing,
        #[Max(120)]
        public readonly ?string $titleSuffix,
        #[Max(120)]
        public readonly ?string $mailFromName,
        #[Email, Max(255)]
        public readonly ?string $mailFromAddress,
        public readonly ?array $themeTokens = null,
        public readonly ?array $features = null,
    ) {}
}
