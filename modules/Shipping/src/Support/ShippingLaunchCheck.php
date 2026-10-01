<?php

declare(strict_types=1);

namespace Modules\Shipping\Support;

use Modules\Platform\Contracts\OnboardingCheck;
use Modules\Shipping\Models\ShippingMethod;

final class ShippingLaunchCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Delivery method';
    }

    public function hint(): string
    {
        return 'Settings → Shipping: switch on at least one delivery method with a rate.';
    }

    public function passes(): bool
    {
        return ShippingMethod::query()->where('is_active', true)->exists();
    }
}
