<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class Fulfilled extends OrderState
{
    public static $name = 'fulfilled';

    public function label(): string
    {
        return __('Shipped');
    }
}
