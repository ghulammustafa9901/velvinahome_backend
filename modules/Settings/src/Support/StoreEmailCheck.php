<?php

declare(strict_types=1);

namespace Modules\Settings\Support;

use Modules\Settings\Contracts\OnboardingCheck;
use Modules\Settings\Contracts\TenantSettings;

final class StoreEmailCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Store email sender set';
    }

    public function hint(): string
    {
        return 'Settings → Email: set the address order emails are sent from.';
    }

    public function passes(): bool
    {
        return trim(app(TenantSettings::class)->string('mail.from_address')) !== '';
    }
}
