<?php

declare(strict_types=1);

namespace Modules\Orders\Support;

use Modules\Orders\Models\Order;
use Modules\Settings\Contracts\OnboardingCheck;

final class TestOrderCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Test order placed';
    }

    public function hint(): string
    {
        return 'Place a test order end to end, then cancel it.';
    }

    public function passes(): bool
    {
        return Order::query()->exists();
    }
}
