<?php

declare(strict_types=1);

namespace Modules\Notifications\Handlers;

final class OrderReturnedEmail extends SendOrderEmail
{
    public function templateKey(): string
    {
        return 'order.returned';
    }
}
