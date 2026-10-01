<?php

declare(strict_types=1);

namespace Modules\Cart\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Cart\Actions\SendCartReminders;
use Modules\Support\Queue\TenantAware;

/**
 * Scheduled per active tenant: queue reminders for carts abandoned long enough.
 */
final class SendCartRemindersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use TenantAware;

    public int $tries = 3;

    public function handle(SendCartReminders $send): void
    {
        $send->handle();
    }
}
