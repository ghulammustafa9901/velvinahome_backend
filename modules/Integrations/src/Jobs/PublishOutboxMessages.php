<?php

declare(strict_types=1);

namespace Modules\Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Integrations\Actions\PublishPendingOutboxMessages;
use Modules\Support\Queue\TenantAware;

/**
 * After-commit by contract, not only by queue configuration: whatever connection it is
 * dispatched on, a publish for a rolled-back transaction is discarded.
 */
final class PublishOutboxMessages implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use TenantAware;

    public int $tries = 5;

    public int $uniqueFor = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    /**
     * One pending publish per tenant at a time; ShouldBeUnique keys are cache keys, which are
     * tenant-tagged.
     */
    public function uniqueId(): string
    {
        return 'outbox:'.(string) tenant()?->getTenantKey();
    }

    public function handle(PublishPendingOutboxMessages $publish): void
    {
        $publish->handle();
    }
}
