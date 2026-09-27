<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class Cancelled extends OrderState
{
    public static $name = 'cancelled';

    public function label(): string
    {
        return __('Cancelled');
    }
}
