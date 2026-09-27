<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class PartiallyFulfilled extends OrderState
{
    public static $name = 'partially_fulfilled';

    public function label(): string
    {
        return __('Partially shipped');
    }
}
