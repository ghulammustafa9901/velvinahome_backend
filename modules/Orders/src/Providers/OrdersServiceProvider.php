<?php

declare(strict_types=1);

namespace Modules\Orders\Providers;

use Modules\Orders\Contracts\OrderBook;
use Modules\Orders\Contracts\SalesLedger;
use Modules\Orders\Listeners\CancelOrderOnFailedPayment;
use Modules\Orders\Listeners\ConfirmPaidOrder;
use Modules\Orders\Support\DatabaseSalesLedger;
use Modules\Orders\Support\EloquentOrderBook;
use Modules\Orders\Support\TestOrderCheck;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentFailed;
use Modules\Settings\Contracts\OnboardingChecks;
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
        $this->app->bind(SalesLedger::class, DatabaseSalesLedger::class);
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

    protected function bootModule(): void
    {
        $this->app->make(OnboardingChecks::class)->register(TestOrderCheck::class);
    }
}
