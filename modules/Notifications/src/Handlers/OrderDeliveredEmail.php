<?php

declare(strict_types=1);

namespace Modules\Notifications\Handlers;

final class OrderDeliveredEmail extends SendOrderEmail
{
    public function templateKey(): string
    {
        return 'order.delivered';
    }
}
