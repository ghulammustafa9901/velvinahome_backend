<?php

declare(strict_types=1);

namespace Modules\Imports\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Imports\Actions\RunImport;
use Modules\Support\Queue\TenantAware;

/**
 * One import run. Not retried automatically: a run is idempotent through its mappings, so staff
 * start a new one after fixing whatever the report shows.
 */
final class RunImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use TenantAware;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $runUlid) {}

    public function handle(RunImport $run): void
    {
        $run->handle($this->runUlid);
    }
}
