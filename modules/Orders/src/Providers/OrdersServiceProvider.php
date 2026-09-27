<?php

declare(strict_types=1);

namespace Modules\Orders\Providers;

use Modules\Orders\Contracts\OrderBook;
use Modules\Orders\Listeners\CancelOrderOnFailedPayment;
use Modules\Orders\Listeners\ConfirmPaidOrder;
use Modules\Orders\Support\EloquentOrderBook;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentFailed;
use Modules\Support\Providers\ModuleServiceProvider;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Orders';
    }

    protected function registerModule(): void
    {
        $this->app->bind(OrderBook::class, EloquentOrderBook::class);
    }

    protected function listeners(): array
    {
        return [
            PaymentConfirmed::class => [ConfirmPaidOrder::class],
            PaymentFailed::class => [CancelOrderOnFailedPayment::class],
        ];
    }

    protected function appendOnlyTables(): array
    {
        return ['order_state_transitions'];
    }
}
