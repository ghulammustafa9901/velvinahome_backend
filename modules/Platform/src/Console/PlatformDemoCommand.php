<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Actions\SeedDemoPlatform;
use Modules\Platform\Enums\DomainKind;

final class PlatformDemoCommand extends Command
{
    protected $signature = 'platform:seed-demo';

    protected $description = 'Provision the local demo: two companies, three brands on *.localhost (idempotent)';

    public function handle(SeedDemoPlatform $seedDemo): int
    {
        foreach ($seedDemo->handle() as $result) {
            $tenant = $result->tenant->fresh(['domains']) ?? $result->tenant;

            $this->components->twoColumnDetail(
                sprintf('%s (%s)', $tenant->name, $tenant->status->value),
                sprintf('http://%s', $tenant->primaryHost(DomainKind::Admin)),
            );

            if ($result->owner?->temporaryPassword !== null) {
                $this->line(sprintf('    owner %s / %s', $result->owner->email, $result->owner->temporaryPassword));
            }
        }

        return self::SUCCESS;
    }
}
