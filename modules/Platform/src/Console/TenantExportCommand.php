<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\ExportTenant;
use Modules\Platform\Models\Tenant;

final class TenantExportCommand extends Command
{
    protected $signature = 'tenant:export {slug}';

    protected $description = 'Export a tenant: pg_dump of its database plus a manifest of its stored files';

    public function handle(ExportTenant $export): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->firstOrFail();

        $backup = $export->handle($tenant);

        $this->components->info(sprintf('Exported to %s (%d bytes, sha256 %s).', $backup->location, $backup->size_bytes, $backup->checksum));

        return self::SUCCESS;
    }
}
