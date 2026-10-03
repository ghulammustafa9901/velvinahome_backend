<?php

declare(strict_types=1);

namespace Modules\Reporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Reporting\Actions\RefreshReports;

/**
 * Scheduled: rebuild the report days whose orders or refunds changed.
 */
final class RefreshReportsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return 'reports-refresh';
    }

    public function handle(RefreshReports $refresh): void
    {
        $refresh->handle();
    }
}
