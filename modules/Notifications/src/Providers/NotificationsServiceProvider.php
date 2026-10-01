<?php

declare(strict_types=1);

namespace Modules\Notifications\Providers;

use Modules\Integrations\Contracts\OutboxHandlers;
use Modules\Notifications\Handlers\CartAbandonedEmail;
use Modules\Notifications\Handlers\OrderCancelledEmail;
use Modules\Notifications\Handlers\OrderConfirmedEmail;
use Modules\Notifications\Handlers\OrderDeliveredEmail;
use Modules\Notifications\Handlers\OrderPlacedEmail;
use Modules\Notifications\Handlers\OrderRefundedEmail;
use Modules\Notifications\Handlers\OrderReturnedEmail;
use Modules\Notifications\Handlers\OrderShippedEmail;
use Modules\Notifications\Seeders\DefaultNotificationTemplatesSeeder;
use Modules\Support\Providers\ModuleServiceProvider;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    public const HANDLERS = [
        'order.placed' => OrderPlacedEmail::class,
        'order.confirmed' => OrderConfirmedEmail::class,
        'order.shipped' => OrderShippedEmail::class,
        'order.delivered' => OrderDeliveredEmail::class,
        'order.cancelled' => OrderCancelledEmail::class,
        'order.refunded' => OrderRefundedEmail::class,
        'order.returned' => OrderReturnedEmail::class,
        'cart.abandoned' => CartAbandonedEmail::class,
    ];

    protected function moduleName(): string
    {
        return 'Notifications';
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath().'/resources/views', 'notifications');

        $registry = $this->app->make(OutboxHandlers::class);

        foreach (self::HANDLERS as $event => $handler) {
            $registry->register($event, $handler);
        }
    }

    protected function tenantSeeders(): array
    {
        return [DefaultNotificationTemplatesSeeder::class];
    }
}
