<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\TenantBackup;
use Modules\Support\Contracts\Action;

/**
 * Keep the newest `platform.provisioning.export_retention` export files of a brand on disk.
 * Older files are deleted; their `tenant_backups` rows stay as history, without a location.
 */
final class PruneTenantExports implements Action
{
    public function handle(Tenant $tenant): int
    {
        $keep = max(1, (int) config('platform.provisioning.export_retention'));

        $old = TenantBackup::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'export')
            ->where('status', 'succeeded')
            ->whereNotNull('location')
            ->orderByDesc('finished_at')
            ->skip($keep)
            ->take(1000)
            ->get();

        foreach ($old as $backup) {
            $dump = (string) $backup->location;
            @unlink($dump);
            @unlink((string) preg_replace('/\.dump$/', '.media.json', $dump));
            $backup->forceFill(['location' => null])->save();
        }

        return $old->count();
    }
}
