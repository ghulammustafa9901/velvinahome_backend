<?php

declare(strict_types=1);

namespace Modules\Settings\Providers;

use Modules\Support\Providers\ModuleServiceProvider;

final class SettingsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Settings';
    }
}
