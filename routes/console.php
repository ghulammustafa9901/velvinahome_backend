<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Modules\Cart\Jobs\SendCartRemindersJob;
use Modules\Integrations\Jobs\PublishOutboxMessages;
use Modules\Inventory\Jobs\RunInventoryMaintenance;
use Modules\Orders\Jobs\CancelUnpaidOrdersJob;
use Modules\Orders\Jobs\SyncShipmentTrackingJob;
use Modules\Platform\Actions\DispatchToActiveTenants;
use Modules\Reporting\Jobs\RefreshReportsJob;

/*
|--------------------------------------------------------------------------
| Schedule (spec §6.11)
|--------------------------------------------------------------------------
|
| One landlord-level schedule. Tenant work is fanned out per active tenant by
| DispatchToActiveTenants, so each job carries its tenant and runs in that tenant's context.
| Later phases add: reservation expiry, payment reconciliation, inventory reconciliation and
| snapshots, low-stock digest, sitemap regeneration, backups.
|
*/

// Safety net for outbox messages whose after-commit publish job was lost or deferred.
Schedule::call(fn () => app(DispatchToActiveTenants::class)->handle(
    fn () => PublishOutboxMessages::dispatch(),
))->everyMinute()->name('tenants:outbox-sweep')->withoutOverlapping()->onOneServer();

// Inventory (spec §6.5): abandoned-checkout reservations, nightly ledger reconciliation and
// daily valuation snapshots, per active tenant.
foreach ([
    'expire-reservations' => fn ($event) => $event->everyMinute(),
    'reconcile' => fn ($event) => $event->dailyAt('02:30'),
    'snapshot' => fn ($event) => $event->dailyAt('23:55'),
] as $task => $frequency) {
    $frequency(Schedule::call(fn () => app(DispatchToActiveTenants::class)->handle(
        fn () => RunInventoryMaintenance::dispatch($task),
    )))->name('tenants:inventory-'.$task)->withoutOverlapping()->onOneServer();
}

// Orders: transfer orders unpaid past the brand's payment window release their stock.
Schedule::call(fn () => app(DispatchToActiveTenants::class)->handle(
    fn () => CancelUnpaidOrdersJob::dispatch(),
))->everyFifteenMinutes()->name('tenants:orders-cancel-unpaid')->withoutOverlapping()->onOneServer();

// Reporting: rebuild the days whose orders or refunds changed (a few minutes behind at most).
Schedule::call(fn () => app(DispatchToActiveTenants::class)->handle(
    fn () => RefreshReportsJob::dispatch(),
))->everyFiveMinutes()->name('tenants:reports-refresh')->withoutOverlapping()->onOneServer();

// Abandoned-cart reminders (brands with the abandoned_cart feature).
Schedule::call(fn () => app(DispatchToActiveTenants::class)->handle(
    fn () => SendCartRemindersJob::dispatch(),
))->everyFifteenMinutes()->name('tenants:cart-reminders')->withoutOverlapping()->onOneServer();

// Courier tracking: deliveries and returns from couriers with an integration.
Schedule::call(fn () => app(DispatchToActiveTenants::class)->handle(
    fn () => SyncShipmentTrackingJob::dispatch(),
))->hourly()->name('tenants:shipment-tracking')->withoutOverlapping()->onOneServer();

// Backups (spec §9): a nightly export of every brand; on Sundays each export is also restored
// into a scratch database and compared with the live one (docs/runbooks/restore-tenant.md).
Schedule::command('tenants:backup')->dailyAt('01:30')->days([1, 2, 3, 4, 5, 6])->withoutOverlapping()->onOneServer();
Schedule::command('tenants:backup --drill')->sundays()->at('01:30')->withoutOverlapping()->onOneServer();
