<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantMigrationRun;
use Modules\Support\Contracts\Action;
use Throwable;

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
    public function __construct(
        private readonly MigrateTenant $migrateTenant,
        private readonly SeedTenant $seedTenant,
    ) {}

    /**
     * @param  list<string>|null  $onlySlugs
     * @return list<TenantMigrationRun>
     */
    public function handle(?array $onlySlugs = null): array
    {
        $runs = [];

        $tenants = Tenant::listOf(Tenant::query()
            ->whereIn('status', array_map(
                fn (TenantStatus $status): string => $status->value,
                array_filter(TenantStatus::cases(), fn (TenantStatus $status): bool => $status->isMigratable()),
            ))
            ->has('databaseCredentials')
            ->when($onlySlugs !== null, fn ($query) => $query->whereIn('slug', $onlySlugs))
            ->orderBy('slug'));

        foreach ($tenants as $tenant) {
            $run = $this->migrateTenant->handle($tenant);

            // Converge module defaults (new permissions, a default location for a module
            // that did not exist when the brand was provisioned). Seeders are idempotent and
            // never undo a brand's own changes. Provisioning tenants seed as their own step.
            if ($run->status === 'succeeded' && $tenant->status !== TenantStatus::Provisioning) {
                try {
                    $this->seedTenant->handle($tenant);
                } catch (Throwable $exception) {
                    $run->forceFill(['status' => 'failed', 'error' => 'Defaults: '.mb_substr($exception->getMessage(), 0, 3900)])->save();
                }
            }

            $runs[] = $run;
        }

        return $runs;
    }
}
