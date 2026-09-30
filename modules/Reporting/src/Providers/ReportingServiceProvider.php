<?php

declare(strict_types=1);

namespace Modules\Reporting\Providers;

use Modules\Reporting\Console\ReportsRefreshCommand;
use Modules\Reporting\Console\ReportsVerifyCommand;
use Modules\Support\Providers\ModuleServiceProvider;

final class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Reporting';
    }

    protected function consoleCommands(): array
    {
        return [ReportsRefreshCommand::class, ReportsVerifyCommand::class];
    }
}
