<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Whether requests on the tenant's hosts may be served. A provisioning tenant is not
     * served yet; a suspended one serves a maintenance response and blocks admin login.
     */
    public function isServable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether scheduled tenant work and `tenants:migrate` include this tenant. Suspended
     * tenants keep migrating so their schema never falls behind the release.
     */
    public function isMigratable(): bool
    {
        return in_array($this, [self::Active, self::Suspended, self::Provisioning], true);
    }
}
