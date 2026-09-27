<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class AwaitingConfirmation extends OrderState
{
    public static $name = 'awaiting_confirmation';

    public function label(): string
    {
        return __('Awaiting confirmation');
    }

    public function cancellable(): bool
    {
        return true;
    }
}
