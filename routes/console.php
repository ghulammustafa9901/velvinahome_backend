<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Modules\Integrations\Jobs\PublishOutboxMessages;
use Modules\Platform\Actions\DispatchToActiveTenants;

/*
|--------------------------------------------------------------------------
| Schedule (spec §6.11)
|--------------------------------------------------------------------------
|
| One landlord-level schedule. Tenant work is fanned out per active tenant by
| DispatchToActiveTenants, so each job carries its tenant and runs in that tenant's context.
| Later phases add: reservation expiry, payment reconciliation, inventory reconciliation and
| snapshots, report aggregation, low-stock digest, sitemap regeneration, backups.
|
*/

// Safety net for outbox messages whose after-commit publish job was lost or deferred.
Schedule::call(fn () => app(DispatchToActiveTenants::class)->handle(
    fn () => PublishOutboxMessages::dispatch(),
))->everyMinute()->name('tenants:outbox-sweep')->withoutOverlapping()->onOneServer();
