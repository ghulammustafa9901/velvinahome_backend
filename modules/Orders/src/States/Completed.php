<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class Completed extends OrderState
{
    public static $name = 'completed';

    public function label(): string
    {
        return __('Completed');
    }
}
