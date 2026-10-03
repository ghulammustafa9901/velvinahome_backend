<?php

declare(strict_types=1);

namespace Modules\Orders\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Orders\Actions\CancelUnpaidOrders;

/**
 * Scheduled: cancel transfer orders left unpaid past the payment window.
 */
final class CancelUnpaidOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function handle(CancelUnpaidOrders $cancel): void
    {
        $cancel->handle();
    }
}
