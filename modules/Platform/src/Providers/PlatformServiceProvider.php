<?php

declare(strict_types=1);

namespace Modules\Platform\Providers;

use Modules\Support\Providers\ModuleServiceProvider;

final class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Platform';
    }
}
