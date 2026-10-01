<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\ExportTenant;
use Modules\Platform\Actions\RestoreTenant;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantBackup;

/**
 * `tenant:restore bravo` runs a restore drill from a fresh export (safe, the brand is untouched).
 * `tenant:restore bravo --backup=42 --in-place --confirm=bravo` replaces a suspended brand's
 * database with that backup (docs/runbooks/restore-tenant.md).
 */
final class TenantRestoreCommand extends Command
{
    protected $signature = 'tenant:restore {slug}
        {--backup= : ID of the export to restore (default: a fresh export for a drill, the latest export in place)}
        {--in-place : Replace the live database (the brand must be suspended)}
        {--confirm= : Type the slug again to confirm an in-place restore}';

    protected $description = 'Restore drill (default) or in-place restore of one tenant from a tenant:export dump';

    public function handle(RestoreTenant $restore, ExportTenant $export): int
    {
        $slug = (string) $this->argument('slug');
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $inPlace = (bool) $this->option('in-place');

        if ($inPlace && $this->option('confirm') !== $slug) {
            $this->components->error('An in-place restore replaces the live database. Pass --confirm='.$slug.' to go ahead.');

            return self::FAILURE;
        }

        $backup = $this->backup($tenant, $inPlace, $export);

        if ($backup === null) {
            $this->components->error('No successful export of this brand was found.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Restoring backup #%d (%s) as a %s', $backup->id, $backup->finished_at?->toDateTimeString() ?? '?', $inPlace ? 'in-place restore' : 'drill'));

        $run = $restore->handle($tenant, $backup, $inPlace ? 'in_place' : 'drill');

        $this->components->twoColumnDetail('Status', $run->status);
        $this->components->twoColumnDetail('Tables checked', (string) $run->tables_checked);
        $this->components->twoColumnDetail('Rows checked', (string) $run->rows_checked);
        $this->components->twoColumnDetail('Duration', ($run->duration_ms ?? 0).' ms');

        foreach ($run->differences as $difference) {
            $this->components->warn($difference);
        }

        if ($run->error !== null) {
            $this->components->error($run->error);
        }

        return $run->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function backup(Tenant $tenant, bool $inPlace, ExportTenant $export): ?TenantBackup
    {
        if ($this->option('backup') !== null) {
            return TenantBackup::query()->where('tenant_id', $tenant->id)->find((int) $this->option('backup'));
        }

        if (! $inPlace) {
            return $export->handle($tenant);
        }

        return TenantBackup::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'export')
            ->where('status', 'succeeded')
            ->latest('finished_at')
            ->first();
    }
}
