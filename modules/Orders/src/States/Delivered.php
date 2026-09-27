<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class Delivered extends OrderState
{
    public static $name = 'delivered';

    public function label(): string
    {
        return __('Delivered');
    }
}
