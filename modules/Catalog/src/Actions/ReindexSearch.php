<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Modules\Catalog\Contracts\SearchIndex;
use Modules\Platform\Contracts\TenantDirectory;
use Modules\Support\Contracts\Action;
use Throwable;

/**
 * `search:reindex`: tenant by tenant, each inside its own context; one failing tenant is
 * reported and the rest continue.
 */
final class ReindexSearch implements Action
{
    public function __construct(
        private readonly TenantDirectory $tenants,
        private readonly SearchIndex $index,
    ) {}

    /**
     * @param  list<string>|null  $tenantIds
     * @return array<string, int|string> tenant id => documents indexed, or the error
     */
    public function handle(?array $tenantIds = null): array
    {
        $report = [];

        foreach ($tenantIds ?? $this->tenants->activeTenantIds() as $tenantId) {
            try {
                $report[$tenantId] = $this->tenants->runIn($tenantId, fn (): int => $this->index->reindex());
            } catch (Throwable $exception) {
                report($exception);
                $report[$tenantId] = 'failed: '.$exception->getMessage();
            }
        }

        return $report;
    }
}
