<?php

declare(strict_types=1);

namespace Modules\Imports\Providers;

use Modules\Imports\Console\ImportCsvCommand;
use Modules\Support\Providers\ModuleServiceProvider;
use Modules\Support\Security\EncryptedColumn;

final class ImportsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Imports';
    }

    protected function consoleCommands(): array
    {
        return [ImportCsvCommand::class];
    }

    protected function encryptedColumns(): array
    {
        return [EncryptedColumn::make('import_runs', 'credentials')];
    }
}
