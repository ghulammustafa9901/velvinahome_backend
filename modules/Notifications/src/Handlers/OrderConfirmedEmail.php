<?php

declare(strict_types=1);

namespace Modules\Notifications\Handlers;

final class OrderConfirmedEmail extends SendOrderEmail
{
    public function templateKey(): string
    {
        return 'order.confirmed';
    }
}
