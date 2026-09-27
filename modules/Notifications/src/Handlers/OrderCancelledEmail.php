<?php

declare(strict_types=1);

namespace Modules\Notifications\Handlers;

final class OrderCancelledEmail extends SendOrderEmail
{
    public function templateKey(): string
    {
        return 'order.cancelled';
    }
}
