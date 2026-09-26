<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

enum PlatformRole: string
{
    case Owner = 'owner';
    case Engineer = 'engineer';
    case Support = 'support';

    /**
     * Creating, suspending and deleting tenants is not a support task.
     */
    public function canManageTenants(): bool
    {
        return $this !== self::Support;
    }
}
