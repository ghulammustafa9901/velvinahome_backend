<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class Confirmed extends OrderState
{
    public static $name = 'confirmed';

    public function label(): string
    {
        return __('Confirmed');
    }

    public function cancellable(): bool
    {
        return true;
    }
}
