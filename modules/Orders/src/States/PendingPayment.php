<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class PendingPayment extends OrderState
{
    public static $name = 'pending_payment';

    public function label(): string
    {
        return __('Awaiting payment');
    }

    public function cancellable(): bool
    {
        return true;
    }
}
