<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantMigrationRun;
use Modules\Support\Contracts\Action;

/**
 * `tenants:migrate`: every tenant database, every deploy (spec §4.6, §4.7).
 *
 * A failing tenant does not stop the others from being attempted, so the report names every
 * tenant that needs attention in one pass; any failure still fails the command and blocks
 * the release. Migrations are expand/contract, so tenants on either side of a failed one
 * remain compatible with the running code.
 */
final class MigrateTenants implements Action
{
    public function __construct(private readonly MigrateTenant $migrateTenant) {}

    /**
     * @param  list<string>|null  $onlySlugs
     * @return list<TenantMigrationRun>
     */
    public function handle(?array $onlySlugs = null): array
    {
        $runs = [];

        $tenants = Tenant::query()
            ->whereIn('status', array_map(
                fn (TenantStatus $status): string => $status->value,
                array_filter(TenantStatus::cases(), fn (TenantStatus $status): bool => $status->isMigratable()),
            ))
            ->has('databaseCredentials')
            ->when($onlySlugs !== null, fn ($query) => $query->whereIn('slug', $onlySlugs))
            ->orderBy('slug')
            ->get();

        foreach ($tenants as $tenant) {
            $runs[] = $this->migrateTenant->handle($tenant);
        }

        return $runs;
    }
}
