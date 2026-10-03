<?php

declare(strict_types=1);

namespace Modules\Support\Providers;

use Modules\Support\Actions\ReencryptStoredSecrets;
use Modules\Support\Console\MigrateDatabaseCommand;
use Modules\Support\Console\SecretsReencryptCommand;
use Modules\Support\Contracts\ReencryptsStoredSecrets;

/**
 * The shared kernel. It holds no domain rules and depends on no other module, which is what
 * lets every other module depend on it without creating a cycle (ADR-0007).
 */
final class SupportServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Support';
    }

    protected function registerModule(): void
    {
        $this->app->bind(ReencryptsStoredSecrets::class, ReencryptStoredSecrets::class);
    }

    protected function consoleCommands(): array
    {
        return [
            MigrateDatabaseCommand::class,
            SecretsReencryptCommand::class,
        ];
    }
}
