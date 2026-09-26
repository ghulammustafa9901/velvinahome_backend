<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\MigrateTenants;
use Modules\Platform\Models\TenantMigrationRun;

/**
 * Part of every deployment. Exits non-zero if any tenant failed, which blocks the release.
 *
 * Deliberately named like stancl/tenancy's command; ours replaces it because it runs as the
 * per-tenant migration role, in one transaction per tenant, and records every run.
 */
final class TenantsMigrateCommand extends Command
{
    protected $signature = 'tenants:migrate {--tenant=* : Limit to these tenant slugs}';

    protected $description = 'Migrate every tenant database (transactional per tenant; non-zero exit on any failure)';

    public function handle(MigrateTenants $migrateTenants): int
    {
        /** @var list<string> $only */
        $only = $this->option('tenant');

        $runs = $migrateTenants->handle($only === [] ? null : $only);

        if ($runs === []) {
            $this->components->warn('No tenants to migrate.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($runs as $run) {
            /** @var TenantMigrationRun $run */
            $slug = $run->loadMissing('tenant')->tenant->slug ?? $run->tenant_id;

            if ($run->status === 'succeeded') {
                $this->components->twoColumnDetail($slug, sprintf('<fg=green>%d applied</>', $run->migrations_applied));

                continue;
            }

            $failed++;
            $this->components->twoColumnDetail($slug, '<fg=red>FAILED — rolled back</>');
            $this->line('    '.$run->error);
        }

        if ($failed > 0) {
            $this->components->error("{$failed} tenant(s) failed to migrate. The release is blocked.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
