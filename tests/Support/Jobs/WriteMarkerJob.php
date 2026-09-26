<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Settings\Actions\UpdateSetting;
use Modules\Support\Queue\TenantAware;

/**
 * Writes a marker setting into whichever tenant the job runs in, so a test can see where a
 * queued job actually executed.
 */
final class WriteMarkerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use TenantAware;

    public int $tries = 1;

    public function __construct(public readonly string $marker) {}

    public function handle(UpdateSetting $update): void
    {
        $update->handle('probe', $this->marker, (string) tenant()?->getTenantKey());
    }
}
