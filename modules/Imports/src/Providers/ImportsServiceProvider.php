<?php

declare(strict_types=1);

namespace Modules\Imports\Providers;

use Modules\Support\Providers\ModuleServiceProvider;

final class ImportsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Imports';
    }
}
