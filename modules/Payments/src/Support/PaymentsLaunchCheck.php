<?php

declare(strict_types=1);

namespace Modules\Payments\Support;

use Modules\Payments\Models\PaymentMethod;
use Modules\Settings\Contracts\OnboardingCheck;

final class PaymentsLaunchCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Payment method';
    }

    public function hint(): string
    {
        return 'Settings → Payment methods: switch on cash on delivery or bank transfer.';
    }

    public function passes(): bool
    {
        return PaymentMethod::query()->where('is_enabled', true)->exists();
    }
}
