<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\ExportTenant;
use Modules\Platform\Actions\PruneTenantExports;
use Modules\Platform\Actions\RestoreTenant;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;
use RuntimeException;
use Throwable;

/**
 * Scheduled: a nightly export of every live brand, old export files pruned, and with --drill a
 * restore drill of each fresh export (spec §9: restore is practised, not assumed). One brand's
 * failure is reported and does not stop the others.
 */
final class TenantsBackupCommand extends Command
{
    protected $signature = 'tenants:backup {--drill : Also restore each export into a scratch database and compare}';

    protected $description = 'Export every active or suspended tenant, prune old exports, optionally run restore drills';

    public function handle(ExportTenant $export, RestoreTenant $restore, PruneTenantExports $prune): int
    {
        $failures = 0;

        /** @var iterable<Tenant> $tenants */
        $tenants = Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Suspended->value])
            ->orderBy('slug')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $backup = $export->handle($tenant);
                $line = sprintf('%s: exported #%d (%d bytes)', $tenant->slug, $backup->id, $backup->size_bytes);

                if ($this->option('drill')) {
                    $run = $restore->handle($tenant, $backup);
                    $line .= sprintf(', drill %s (%d tables, %d rows)', $run->status, $run->tables_checked, $run->rows_checked);
                    $failures += $run->status === 'succeeded' ? 0 : 1;

                    if ($run->status !== 'succeeded') {
                        report(new RuntimeException("Restore drill failed for {$tenant->slug}: ".($run->error ?? implode('; ', $run->differences))));
                    }
                }

                $this->components->info($line);
            } catch (Throwable $e) {
                $failures++;
                report($e);
                $this->components->error($tenant->slug.': '.$e->getMessage());
            }

            $prune->handle($tenant);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
