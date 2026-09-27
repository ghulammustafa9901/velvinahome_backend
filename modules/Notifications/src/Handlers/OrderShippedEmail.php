<?php

declare(strict_types=1);

namespace Modules\Notifications\Handlers;

final class OrderShippedEmail extends SendOrderEmail
{
    public function templateKey(): string
    {
        return 'order.shipped';
    }
}
