<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Modules\Cart\Jobs\SendCartRemindersJob;
use Modules\Integrations\Jobs\PublishOutboxMessages;
use Modules\Inventory\Jobs\RunInventoryMaintenance;
use Modules\Orders\Jobs\CancelUnpaidOrdersJob;
use Modules\Orders\Jobs\SyncShipmentTrackingJob;
use Modules\Reporting\Jobs\RefreshReportsJob;

/*
|--------------------------------------------------------------------------
| Schedule (spec §6.11)
|--------------------------------------------------------------------------
|
| Each task queues its job; the job does the work, so a slow task never delays the next tick.
|
*/

// Safety net for outbox messages whose after-commit publish job was lost or deferred.
Schedule::job(new PublishOutboxMessages)->everyMinute()->name('outbox-sweep')->withoutOverlapping()->onOneServer();

// Inventory (spec §6.5): abandoned-checkout reservations, nightly ledger reconciliation and
// daily valuation snapshots.
Schedule::job(new RunInventoryMaintenance('expire-reservations'))->everyMinute()->name('inventory-expire-reservations')->withoutOverlapping()->onOneServer();
Schedule::job(new RunInventoryMaintenance('reconcile'))->dailyAt('02:30')->name('inventory-reconcile')->withoutOverlapping()->onOneServer();
Schedule::job(new RunInventoryMaintenance('snapshot'))->dailyAt('23:55')->name('inventory-snapshot')->withoutOverlapping()->onOneServer();

// Orders: transfer orders unpaid past the payment window release their stock.
Schedule::job(new CancelUnpaidOrdersJob)->everyFifteenMinutes()->name('orders-cancel-unpaid')->withoutOverlapping()->onOneServer();

// Reporting: rebuild the days whose orders or refunds changed (a few minutes behind at most).
Schedule::job(new RefreshReportsJob)->everyFiveMinutes()->name('reports-refresh')->withoutOverlapping()->onOneServer();

// Abandoned-cart reminders (only with the abandoned_cart feature on).
Schedule::job(new SendCartRemindersJob)->everyFifteenMinutes()->name('cart-reminders')->withoutOverlapping()->onOneServer();

// Courier tracking: deliveries and returns from couriers with an integration.
Schedule::job(new SyncShipmentTrackingJob)->hourly()->name('shipment-tracking')->withoutOverlapping()->onOneServer();
