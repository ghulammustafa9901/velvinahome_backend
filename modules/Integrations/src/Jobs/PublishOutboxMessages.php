<?php

declare(strict_types=1);

namespace Modules\Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Integrations\Actions\PublishPendingOutboxMessages;
use Modules\Support\Queue\TenantAware;

final class PublishOutboxMessages implements ShouldBeUnique, ShouldQueue
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
