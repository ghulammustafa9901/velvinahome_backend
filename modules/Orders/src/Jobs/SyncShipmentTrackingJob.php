<?php

declare(strict_types=1);

namespace Modules\Orders\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Orders\Actions\SyncShipmentTracking;

/**
 * Scheduled per active tenant: pick up deliveries and returns from courier integrations.
 */
final class SyncShipmentTrackingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function handle(SyncShipmentTracking $sync): void
    {
        $sync->handle();
    }
}
