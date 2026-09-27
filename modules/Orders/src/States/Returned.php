<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class Returned extends OrderState
{
    public static $name = 'returned';

    public function label(): string
    {
        return __('Returned');
    }
}
