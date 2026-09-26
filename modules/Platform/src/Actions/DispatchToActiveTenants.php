<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;
use Modules\Support\Contracts\Action;
use Throwable;

/**
 * The scheduler's fan-out (spec §4.4, §6.11): one landlord-level schedule entry dispatches a
 * tenant-scoped job once per active tenant, from inside that tenant's context — so the job's
 * payload carries the tenant id and the worker re-initialises the right tenant.
 *
 * Suspended and provisioning tenants are skipped. A failure for one tenant is reported and
 * the loop continues; one broken brand never stops scheduled work for the others.
 */
final class DispatchToActiveTenants implements Action
{
    /**
     * @param  callable(Tenant): void  $dispatch  called inside each tenant's context
     * @return array<string, string> tenant slug => "dispatched" | "failed: <reason>"
     */
    public function handle(callable $dispatch): array
    {
        $report = [];

        $tenants = Tenant::listOf(Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->has('databaseCredentials')
            ->with('databaseCredentials')
            ->orderBy('slug'));

        foreach ($tenants as $tenant) {
            try {
                // A block, not an arrow function: `Job::dispatch()` returns a PendingDispatch
                // that dispatches when destroyed. Returned out of run(), it would be destroyed
                // after tenancy ended and the job would be queued without its tenant.
                $tenant->run(function () use ($dispatch, $tenant): void {
                    $dispatch($tenant);
                });
                $report[$tenant->slug] = 'dispatched';
            } catch (Throwable $exception) {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }

                report($exception);
                $report[$tenant->slug] = 'failed: '.mb_substr($exception->getMessage(), 0, 200);
            }
        }

        return $report;
    }
}
