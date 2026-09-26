<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Modules\Support\Providers\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }
}
