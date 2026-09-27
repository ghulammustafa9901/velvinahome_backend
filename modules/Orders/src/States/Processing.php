<?php

declare(strict_types=1);

namespace Modules\Orders\States;

final class Processing extends OrderState
{
    public static $name = 'processing';

    public function label(): string
    {
        return __('Processing');
    }

    public function cancellable(): bool
    {
        return true;
    }
}
